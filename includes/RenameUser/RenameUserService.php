<?php

namespace Telepedia\Extensions\TelepediaCore\RenameUser;

use Exception;
use JobSpecification;
use MediaWiki\JobQueue\JobQueueGroupFactory;
use MediaWiki\Logger\LoggerFactory;
use MediaWiki\Session\SessionManager;
use MediaWiki\User\UserFactory;
use MediaWiki\User\UserIdentity;
use MediaWiki\User\UserNameUtils;
use MediaWiki\User\UserRigorOptions;
use Psr\Log\LoggerInterface;
use StatusValue;
use Telepedia\Extensions\UAM\GlobalUserService;
use Wikimedia\Rdbms\IConnectionProvider;

/**
 * This is a slight hacky backport of 1.44's RenameUser functionality (since the version for 1.43 does not
 * support $wgSharedDB) slightly modified to ask the GlobalUserService for the list of wikis the user is
 * active on instead of queueing the job for every wiki in $wgLocalDatabses.
 *
 * It largely just calls out to RenameUserSQL from core to do the actual renaming, however :P
 * @see \MediaWiki\RenameUser\RenameuserSQL
 */
class RenameUserService {

	private LoggerInterface $logger;

	public function __construct(
		private readonly UserFactory $userFactory,
		private readonly IConnectionProvider $connectionProvider,
		private readonly GlobalUserService $globalUserService,
		private readonly JobQueueGroupFactory $jobQueueFactory,
		private readonly UserNameUtils $userNameUtils,
	) {
		$this->logger = LoggerFactory::getInstance( 'Renameuser' );
	}

	/**
	 * Rename a user across the entire wiki farm.
	 * @param string $oldName Current username
	 * @param string $newName New username
	 * @param UserIdentity $performer The user performing the rename
	 * @param string $reason Reason for the rename
	 * @param bool $movePages Whether to move user pages and subpages
	 * @param bool $suppressRedirects Whether to suppress redirects when moving pages
	 * @return StatusValue
	 */
	public function renameUser(
		string $oldName,
		string $newName,
		UserIdentity $performer,
		string $reason = '',
		bool $movePages = true,
		bool $suppressRedirects = false
	): StatusValue {
		// Validate old user exists
		$oldUser = $this->userFactory->newFromName( $oldName );
		if ( !$oldUser || !$oldUser->isRegistered() ) {
			return StatusValue::newFatal( 'renameusererrordoesnotexist', $oldName );
		}

		// Validate that the new user does not exist, and the username can be created
		$newUser = $this->userFactory->newFromName( $newName, UserRigorOptions::RIGOR_CREATABLE );
		if ( !$newUser ) {
			return StatusValue::newFatal( 'renameusererrorinvalid', $newName );
		}
		if ( $newUser->isRegistered() ) {
			return StatusValue::newFatal( 'renameusererrorexists', $newName );
		}

		// if the user is temporary, skip them (this shouldn't happen since we don't allow temp usernames on Telepedia)
		// but alas paranoia!!!
		if ( $this->userNameUtils->isTemp( $oldName ) ) {
			return StatusValue::newFatal( 'renameuser-error-temp-user', $oldName );
		}

		$uid = $oldUser->getId();

		$this->logger->info(
			"Starting global rename of {$oldName} (ID: {$uid}) to {$newName}"
		);

		// Rename the user in the actor and user table
		$globalStatus = $this->performGlobalRename( $oldName, $newName, $uid );
		if ( !$globalStatus->isGood() ) {
			return $globalStatus;
		}

		// Now the user has been renamed, invalidate their session and purge the cache
		// @TODO: maybe we do this before? We need to log the user out before as well I think?!
		$freshUser = $this->userFactory->newFromId( $uid );
		SessionManager::singleton()->invalidateSessionsForUser( $freshUser );
		$freshUser->invalidateCache();

		// return wikis where this user is active/has made actions
		$wikis = $this->globalUserService->getAttachedWikis( $uid );

		if ( empty( $wikis ) ) {
			$this->logger->info(
				"No attached wikis found for user {$uid}, global rename complete"
			);
			return StatusValue::newGood();
		}

		// Send the job to each wiki's queue to do the rename thank you very much
		foreach ( $wikis as $wiki => $_ ) {
			$jobQueueGroup = $this->jobQueueFactory->makeJobQueueGroup( $wiki );

			$jobQueueGroup->push(
				new JobSpecification(
					CrossWikiRenameUserJob::JOB_NAME,
					[
						'uid' => $uid,
						'oldname' => $oldName,
						'newname' => $newName,
						'performer' => $performer->getId(),
						'reason' => $reason,
						'movePages' => $movePages,
						'suppressRedirects' => $suppressRedirects,
					]
				)
			);

			$this->logger->debug( "Queued rename job for wiki {$wiki}" );
		}

		$this->logger->info(
			"Global rename of {$oldName} to {$newName} complete. " .
			"Queued " . count( $wikis ) . " per-wiki jobs."
		);

		return StatusValue::newGood();
	}

	/**
	 * Perform the global rename on the shared database.
	 * Updates the user and actor tables atomically with a row lock to prevent
	 * race conditions. This mirrors what RenameuserSQL does for these two tables,
	 * but we do it once globally rather than on every wiki.
	 * @param string $oldName Current username
	 * @param string $newName New username
	 * @param int $uid User ID
	 * @return StatusValue
	 */
	private function performGlobalRename(
		string $oldName,
		string $newName,
		int $uid
	): StatusValue {
		$dbw = $this->connectionProvider->getPrimaryDatabase();

		try {
			$dbw->startAtomic( __METHOD__ );

			// copied from RenameuserSQL::lockUserAndGetId()
			$lockedId = (int)$dbw->newSelectQueryBuilder()
				->select( 'user_id' )
				->forUpdate()
				->from( 'user' )
				->where( [ 'user_name' => $oldName ] )
				->caller( __METHOD__ )
				->fetchField();

			if ( !$lockedId ) {
				$dbw->cancelAtomic( __METHOD__ );
				return StatusValue::newFatal( 'renameusererrordoesnotexist', $oldName );
			}

			// a la update user table
			$this->logger->debug( "Updating user table: {$oldName} -> {$newName}" );
			$dbw->newUpdateQueryBuilder()
				->update( 'user' )
				->set( [
					'user_name' => $newName,
					'user_touched' => $dbw->timestamp()
				] )
				->where( [
					'user_id' => $uid,
					'user_name' => $oldName
				] )
				->caller( __METHOD__ )
				->execute();

			// a la update actor table
			$this->logger->debug( "Updating actor table: {$oldName} -> {$newName}" );
			$dbw->newUpdateQueryBuilder()
				->update( 'actor' )
				->set( [ 'actor_name' => $newName ] )
				->where( [
					'actor_user' => $uid,
					'actor_name' => $oldName
				] )
				->caller( __METHOD__ )
				->execute();

			$dbw->endAtomic( __METHOD__ );
		} catch ( Exception $e ) {
			$this->logger->error( "Global rename failed: " . $e->getMessage() );
			return StatusValue::newFatal( 'renameuser-error-request', $e->getMessage() );
		}

		return StatusValue::newGood();
	}
}
