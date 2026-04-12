<?php

namespace Telepedia\Extensions\TelepediaCore\RenameUser;

use Exception;
use Job;
use JobSpecification;
use ManualLogEntry;
use MediaWiki\HookContainer\HookRunner;
use MediaWiki\Logger\LoggerFactory;
use MediaWiki\MediaWikiServices;
use MediaWiki\Page\MovePageFactory;
use MediaWiki\RenameUser\RenameuserSQL;
use MediaWiki\Specials\SpecialLog;
use MediaWiki\Title\Title;
use MediaWiki\User\User;
use Psr\Log\LoggerInterface;
use WikiMap;
use Wikimedia\Rdbms\IDBAccessObject;
use Wikimedia\Rdbms\SelectQueryBuilder;

/**
 * A derived job to rename a user on a local wiki when using $wgSharedDB
 * * Backport of the 1.44 RenameUserDerivedJob pattern see T104830.
 * @see RenameUserService
 * @see \MediaWiki\RenameUser\RenameuserSQL
 */
class CrossWikiRenameUserJob extends Job {

	public const JOB_NAME = 'CrossWikiRenameUserJob';

	private LoggerInterface $logger;

	public function __construct( array $params ) {
		parent::__construct( self::JOB_NAME, $params );
		$this->logger = LoggerFactory::getInstance( 'Renameuser' );
	}

	public function run(): bool {
		$uid = $this->params['uid'];
		$oldName = $this->params['oldname'];
		$newName = $this->params['newname'];
		$performerUid = $this->params['performer'];
		$reason = $this->params['reason'] ?? '';
		$movePages = $this->params['movePages'] ?? true;
		$suppressRedirects = $this->params['suppressRedirects'] ?? false;

		$wikiId = WikiMap::getCurrentWikiId();

		$this->logger->info(
			"Starting derived rename on {$wikiId}: {$oldName} -> {$newName}"
		);

		$services = MediaWikiServices::getInstance();
		$userFactory = $services->getUserFactory();
		$titleFactory = $services->getTitleFactory();
		$hookRunner = new HookRunner( $services->getHookContainer() );
		$dbProvider = $services->getConnectionProvider();
		$jobQueueGroup = $services->getJobQueueGroup();
		$config = $services->getMainConfig();

		$performer = $userFactory->newFromId( $performerUid );
		$updateRowsPerJob = $config->get( 'UpdateRowsPerJob' );

		// We create a RenameuserSQL instance purely to trigger the hook in its constructor,
		// which populates tables and tablesJob. We never call rename() on it,
		// we just need to know which tables are added when the hook fires
		// checkIfUserExists is false because the user table was already renamed globally;
		// the old name no longer exists in the shared user table.
		$renameUserSql = new RenameuserSQL(
			$oldName,
			$newName,
			$uid,
			$performer,
			[ 'checkIfUserExists' => false ]
		);
		$extensionTables = $renameUserSql->tables;
		$extensionTablesJob = $renameUserSql->tablesJob;

		$dbw = $dbProvider->getPrimaryDatabase();

		// Do NOT use ATOMIC_CANCELABLE here. It creates a SAVEPOINT with snapshot isolation,
		// which causes Error 1020 conflicts when the page moves later try to update
		// change_tag_def (read during log entry insert, then written during redirect tagging).
		$dbw->startAtomic( __METHOD__ );

		$hookRunner->onRenameUserPreRename( $uid, $oldName, $newName );

		// Update block_target if not shared
		if ( !$this->isTableShared( 'block_target' ) ) {
			$this->logger->debug( "Updating block_target on {$wikiId}" );
			$dbw->newUpdateQueryBuilder()
				->update( 'block_target' )
				->set( [ 'bt_user_text' => $newName ] )
				->where( [ 'bt_user' => $uid, 'bt_user_text' => $oldName ] )
				->caller( __METHOD__ )
				->execute();
		}

		// Update logging table (block/rights logs etc. excludes renameuser logs per T200731)
		$oldTitle = $titleFactory->makeTitle( NS_USER, $oldName );
		$newTitle = $titleFactory->makeTitle( NS_USER, $newName );
		$logTypesOnUser = array_diff( SpecialLog::getLogTypesOnUser(), [ 'renameuser' ] );

		if ( !$this->isTableShared( 'logging' ) ) {
			$this->logger->debug( "Updating logging on {$wikiId}" );
			$dbw->newUpdateQueryBuilder()
				->update( 'logging' )
				->set( [ 'log_title' => $newTitle->getDBkey() ] )
				->where( [
					'log_type' => $logTypesOnUser,
					'log_namespace' => NS_USER,
					'log_title' => $oldTitle->getDBkey()
				] )
				->caller( __METHOD__ )
				->execute();
		}

		// Update recentchanges
		if ( !$this->isTableShared( 'recentchanges' ) ) {
			$this->logger->debug( "Updating recentchanges on {$wikiId}" );
			$dbw->newUpdateQueryBuilder()
				->update( 'recentchanges' )
				->set( [ 'rc_title' => $newTitle->getDBkey() ] )
				->where( [
					'rc_type' => RC_LOG,
					'rc_log_type' => $logTypesOnUser,
					'rc_namespace' => NS_USER,
					'rc_title' => $oldTitle->getDBkey()
				] )
				->caller( __METHOD__ )
				->execute();
		}

		// immediate tables registered by extensions
		foreach ( $extensionTables as $table => $fieldSet ) {
			if ( $this->isTableShared( $table ) ) {
				$this->logger->debug( "Skipping shared table {$table} on {$wikiId}" );
				continue;
			}
			[ $nameCol, $userCol ] = $fieldSet;
			$dbw->newUpdateQueryBuilder()
				->update( $table )
				->set( [ $nameCol => $newName ] )
				->where( [ $nameCol => $oldName, $userCol => $uid ] )
				->caller( __METHOD__ )
				->execute();
		}

		// deferred tables registered by extensions
		$jobs = [];
		foreach ( $extensionTablesJob as $table => $params ) {
			if ( $this->isTableShared( $table ) ) {
				$this->logger->debug( "Skipping shared deferred table {$table} on {$wikiId}" );
				continue;
			}

			$userTextC = $params[RenameuserSQL::NAME_COL];
			$userIDC = $params[RenameuserSQL::UID_COL];
			$timestampC = $params[RenameuserSQL::TIME_COL];

			$res = $dbw->newSelectQueryBuilder()
				->select( [ $timestampC ] )
				->from( $table )
				->where( [ $userTextC => $oldName, $userIDC => $uid ] )
				->orderBy( $timestampC, SelectQueryBuilder::SORT_ASC )
				->caller( __METHOD__ )
				->fetchResultSet();

			$jobParams = [
				'table' => $table,
				'column' => $userTextC,
				'uidColumn' => $userIDC,
				'timestampColumn' => $timestampC,
				'oldname' => $oldName,
				'newname' => $newName,
				'userID' => $uid,
				'minTimestamp' => '0',
				'maxTimestamp' => '0',
				'count' => 0,
			];

			if ( isset( $params['uniqueKey'] ) ) {
				$jobParams['uniqueKey'] = $params['uniqueKey'];
			}

			foreach ( $res as $row ) {
				if ( $jobParams['count'] === 0 ) {
					$jobParams['minTimestamp'] = $row->$timestampC;
				}
				$jobParams['maxTimestamp'] = $row->$timestampC;
				$jobParams['count']++;

				if ( $jobParams['count'] >= $updateRowsPerJob ) {
					$jobs[] = new JobSpecification( 'renameUser', $jobParams, [], $oldTitle );
					$jobParams['minTimestamp'] = '0';
					$jobParams['maxTimestamp'] = '0';
					$jobParams['count'] = 0;
				}
			}

			if ( $jobParams['count'] > 0 ) {
				$jobs[] = new JobSpecification( 'renameUser', $jobParams, [], $oldTitle );
			}
		}

		$contribs = $userFactory->newFromId( $uid )->getEditCount();
		$logEntry = new ManualLogEntry( 'renameuser', 'renameuser' );
		$logEntry->setPerformer( $performer );
		$logEntry->setTarget( $oldTitle );
		$logEntry->setComment( $reason );
		$logEntry->setParameters( [
			'4::olduser' => $oldName,
			'5::newuser' => $newName,
			'6::edits' => $contribs
		] );
		$logid = $logEntry->insert();

		if ( count( $jobs ) > 0 ) {
			$jobQueueGroup->push( $jobs );
			$this->logger->debug(
				"Queued " . count( $jobs ) . " table-update jobs on {$wikiId}"
			);
		}

		$dbw->endAtomic( __METHOD__ );

		$fname = __METHOD__;
		$dbw->onTransactionCommitOrIdle(
			static function () use (
				$dbw, $logEntry, $logid, $fname,
				$hookRunner, $userFactory, $uid, $oldName, $newName
			) {
				$dbw->startAtomic( $fname );
				$user = $userFactory->newFromId( $uid );
				$user->load( IDBAccessObject::READ_LATEST );
				$user->saveSettings();
				$hookRunner->onRenameUserComplete( $uid, $oldName, $newName );
				$logEntry->publish( $logid );
				$dbw->endAtomic( $fname );
			},
			$fname
		);

		if ( $movePages ) {
			$this->moveUserPages(
				$services->getMovePageFactory(),
				$performer,
				$oldName,
				$newName,
				$suppressRedirects
			);
		}

		$this->logger->info(
			"Derived rename complete on {$wikiId}: {$oldName} -> {$newName}"
		);

		return true;
	}

	/**
	 * Move user pages and subpages from old name to new name.
	 * Moves User:OldName, User_talk:OldName, and all subpages under both.
	 * Uses the unsafe move() (no permission checks) since we shouldv'e already passed the auth stage before
	 * this job is ever run
	 * @param MovePageFactory $movePageFactory
	 * @param User $performer
	 * @param string $oldName
	 * @param string $newName
	 * @param bool $suppressRedirects
	 */
	private function moveUserPages(
		MovePageFactory $movePageFactory,
		User $performer,
		string $oldName,
		string $newName,
		bool $suppressRedirects
	): void {
		$titleFactory = MediaWikiServices::getInstance()->getTitleFactory();
		$createRedirect = !$suppressRedirects;

		$oldUserTitle = $titleFactory->makeTitle( NS_USER, $oldName );
		$newUserTitle = $titleFactory->makeTitle( NS_USER, $newName );

		$this->movePageAndSubpages(
			$movePageFactory, $performer, $oldUserTitle, $newUserTitle, $createRedirect
		);

		$oldTalkTitle = $oldUserTitle->getTalkPageIfDefined();
		$newTalkTitle = $newUserTitle->getTalkPageIfDefined();

		if ( $oldTalkTitle && $newTalkTitle ) {
			$this->movePageAndSubpages(
				$movePageFactory, $performer, $oldTalkTitle, $newTalkTitle, $createRedirect
			);
		}
	}

	/**
	 * Move a page and all its subpages.
	 * @param MovePageFactory $movePageFactory
	 * @param User $performer
	 * @param Title $oldTitle
	 * @param Title $newTitle
	 * @param bool $createRedirect
	 */
	private function movePageAndSubpages(
		MovePageFactory $movePageFactory,
		User $performer,
		Title $oldTitle,
		Title $newTitle,
		bool $createRedirect
	): void {
		$movePage = $movePageFactory->newMovePage( $oldTitle, $newTitle );
		$movePage->setMaximumMovedPages( -1 );

		$logMessage = wfMessage(
			'renameuser-move-log',
			$oldTitle->getText(),
			$newTitle->getText()
		)->inContentLanguage()->text();

		try {
			if ( $oldTitle->exists() ) {
				$status = $movePage->move( $performer, $logMessage, $createRedirect );
				if ( !$status->isGood() ) {
					$this->logger->warning(
						"Failed to move {$oldTitle->getPrefixedText()} to " .
						"{$newTitle->getPrefixedText()}: {$status}"
					);
				}
			}
		} catch ( Exception $e ) {
			// Page move failures are non-fatal for the rename itself — the DB
			// updates (logging, block_target etc.) have already committed.
			$this->logger->error(
				"Exception moving {$oldTitle->getPrefixedText()}: " . $e->getMessage()
			);
		}

		try {
			$batchStatus = $movePage->moveSubpages( $performer, $logMessage, $createRedirect );
			foreach ( $batchStatus->getValue() ?? [] as $titleText => $moveStatus ) {
				if ( !$moveStatus->isGood() ) {
					$this->logger->warning(
						"Failed to move subpage {$titleText}: {$moveStatus}"
					);
				}
			}
		} catch ( Exception $e ) {
			// namespace may not support subpages, which will throw an error, but here we don't care,
			// it is irrelevant for the outcome of this job so just swallow the error
			$this->logger->warning(
				"Could not move subpages for {$oldTitle->getPrefixedText()}: " .
				$e->getMessage()
			);
		}
	}

	/**
	 * Check if a table is shared via $wgSharedDB / $wgSharedTables.
	 * See RenameuserSQL::isTableShared().
	 * @param string $table
	 * @return bool
	 */
	private function isTableShared( string $table ): bool {
		global $wgSharedDB, $wgSharedTables;
		return $wgSharedDB && in_array( $table, $wgSharedTables ?? [], true );
	}
}
