<?php

namespace Telepedia\Extensions\TelepediaCore\Specials;

use MediaWiki\CommentStore\CommentStore;
use MediaWiki\Html\Html;
use MediaWiki\HTMLForm\HTMLForm;
use MediaWiki\MainConfigNames;
use MediaWiki\Permissions\PermissionManager;
use MediaWiki\SpecialPage\SpecialPage;
use MediaWiki\Status\Status;
use MediaWiki\Title\TitleFactory;
use MediaWiki\User\UserFactory;
use MediaWiki\User\UserNamePrefixSearch;
use MediaWiki\User\UserNameUtils;
use OOUI\FieldLayout;
use OOUI\HtmlSnippet;
use OOUI\MessageWidget;
use Telepedia\Extensions\TelepediaCore\RenameUser\RenameUserService;
use UserBlockedError;
use Wikimedia\Rdbms\IConnectionProvider;

/**
 * Override core Special:Renameuser that uses our cross-wiki RenameUserService
 * instead of core  RenameuserSQL (which doesn't support $wgSharedDB on 1.43).
 *
 * The form and validation here are lifted from core's almost word for word;
 * the only difference being at the bottom of execute() where we call
 * RenameUserService::renameUser() instead of new RenameuserSQL()->rename().
 *
 */
class SpecialRenameUser extends SpecialPage {

	public function __construct(
		private readonly IConnectionProvider $dbConns,
		private readonly PermissionManager $permissionManager,
		private readonly TitleFactory $titleFactory,
		private readonly UserFactory $userFactory,
		private readonly UserNamePrefixSearch $userNamePrefixSearch,
		private readonly UserNameUtils $userNameUtils,
		private readonly RenameUserService $renameUserService,
	) {
		parent::__construct( 'Renameuser', 'renameuser' );
	}

	public function doesWrites() {
		return true;
	}

	/**
	 * @param null|string $par Parameter passed to the page
	 */
	public function execute( $subPage ) {
		$this->setHeaders();
		$this->addHelpLink( 'https://meta.telepedia.net/wiki/Help:Renaming_your_account', true );

		$this->checkPermissions();
		$this->checkReadOnly();

		$performer = $this->getUser();

		$block = $performer->getBlock();
		if ( $block ) {
			throw new UserBlockedError( $block );
		}

		$out = $this->getOutput();
		$out->addWikiMsg( 'renameuser-summary' );

		$this->useTransactionalTimeLimit();

		$request = $this->getRequest();

		$userNames = $subPage !== null ? explode( '/', $subPage, 2 ) : [];

		$oldName = $request->getText( 'oldusername', $userNames[0] ?? '' );
		$oldName = trim( str_replace( '_', ' ', $oldName ) );
		$oldTitle = $this->titleFactory->makeTitle( NS_USER, $oldName );

		$origNewName = $request->getText( 'newusername', $userNames[1] ?? '' );
		$origNewName = trim( str_replace( '_', ' ', $origNewName ) );

		$newTitle = $this->titleFactory->makeTitleSafe(
			NS_USER, $this->getContentLanguage()->ucfirst( $origNewName )
		);
		$newName = $newTitle ? $newTitle->getText() : '';

		$reason = $request->getText( 'reason' );
		$moveChecked = $request->getBool( 'movepages', !$request->wasPosted() );
		$suppressChecked = $request->getCheck( 'suppressredirect' );

		if ( $oldName !== '' && $newName !== '' && !$request->getCheck( 'confirmaction' ) ) {
			$warnings = $this->getWarnings( $oldName, $newName );
		} else {
			$warnings = [];
		}

		$this->showForm( $oldName, $newName, $warnings, $reason, $moveChecked, $suppressChecked );

		if ( $request->getText( 'wpEditToken' ) === '' ) {
			return;
		}
		if ( $warnings ) {
			return;
		}
		if (
			!$request->wasPosted() ||
			!$performer->matchEditToken( $request->getVal( 'wpEditToken' ) )
		) {
			$out->addHTML( Html::errorBox( $out->msg( 'renameuser-error-request' )->parse() ) );
			return;
		}
		if ( !$newTitle ) {
			$out->addHTML( Html::errorBox(
				$out->msg( 'renameusererrorinvalid' )
					->params( $request->getText( 'newusername' ) )->parse()
			) );
			return;
		}
		if ( $oldName === $newName ) {
			$out->addHTML( Html::errorBox( $out->msg( 'renameuser-error-same-user' )->parse() ) );
			return;
		}

		if ( $this->userNameUtils->isTemp( $oldName ) ) {
			$out->addHTML( Html::errorBox(
				$out->msg( 'renameuser-error-temp-user' )->plaintextParams( $oldName )->parse()
			) );
			return;
		}
		if ( $this->userNameUtils->isTemp( $newName ) ||
			$this->userNameUtils->isTempReserved( $newName )
		) {
			$out->addHTML( Html::errorBox(
				$out->msg( 'renameuser-error-temp-user-reserved' )->plaintextParams( $newName )->parse()
			) );
			return;
		}

		$oldUser = $this->userFactory->newFromName( $oldName, $this->userFactory::RIGOR_NONE );
		$newUser = $this->userFactory->newFromName( $newName, $this->userFactory::RIGOR_CREATABLE );

		if ( !$oldUser ) {
			$out->addHTML( Html::errorBox(
				$out->msg( 'renameusererrorinvalid' )->params( $oldTitle->getText() )->parse()
			) );
			return;
		}
		if ( !$newUser ) {
			$out->addHTML( Html::errorBox(
				$out->msg( 'renameusererrorinvalid' )->params( $newTitle->getText() )->parse()
			) );
			return;
		}

		if ( $oldName !== $this->getContentLanguage()->ucfirst( $oldName ) ) {
			$dbr = $this->dbConns->getReplicaDatabase();
			$uid = $dbr->newSelectQueryBuilder()
				->select( 'user_id' )
				->from( 'user' )
				->where( [ 'user_name' => $oldName ] )
				->caller( __METHOD__ )
				->fetchField();
			if ( $uid === false ) {
				if ( !$this->getConfig()->get( MainConfigNames::CapitalLinks ) ) {
					$uid = 0;
				} else {
					$uid = $oldUser->idForName();
					$oldTitle = $this->titleFactory->makeTitleSafe( NS_USER, $oldUser->getName() );
					if ( !$oldTitle ) {
						$out->addHTML( Html::errorBox(
							$out->msg( 'renameusererrorinvalid' )->params( $oldName )->parse()
						) );
						return;
					}
					$oldName = $oldTitle->getText();
				}
			}
		} else {
			$uid = $oldUser->idForName();
		}

		if ( $uid === 0 ) {
			$out->addHTML( Html::errorBox(
				$out->msg( 'renameusererrordoesnotexist' )->params( $oldName )->parse()
			) );
			return;
		}

		if ( $newUser->idForName() !== 0 ) {
			$out->addHTML( Html::errorBox(
				$out->msg( 'renameusererrorexists' )->params( $newName )->parse()
			) );
			return;
		}

		if ( $oldUser->equals( $performer ) ) {
			$out->addHTML( Html::errorBox(
				$out->msg( 'renameuser-error-self-rename' )->parse()
			) );
			return;
		}

		if ( !$this->getHookRunner()->onRenameUserAbort( $uid, $oldName, $newName ) ) {
			return;
		}

		/**
		 * Telepedia Change
		 */
		$movePages = $moveChecked
			&& $this->permissionManager->userHasRight( $performer, 'move' );
		$suppressRedirect = $suppressChecked
			&& $this->permissionManager->userHasRight( $performer, 'suppressredirect' );

		$status = $this->renameUserService->renameUser(
			$oldTitle->getText(),
			$newTitle->getText(),
			$performer,
			$reason,
			$movePages,
			$suppressRedirect
		);
		/** End Telepedia Change */

		if ( !$status->isGood() ) {
			$out->addHTML( Html::errorBox( Status::wrap( $status )->getHTML() ) );
			return;
		}

		$out->addHTML(
			Html::successBox(
				$out->msg( 'renameusersuccess' )
					->params( $oldTitle->getText(), $newTitle->getText() )
					->parse()
			)
		);
	}

	private function getWarnings( $oldName, $newName ) {
		$warnings = [];
		$oldUser = $this->userFactory->newFromName( $oldName, $this->userFactory::RIGOR_NONE );
		if ( $oldUser && !$oldUser->isTemp() && $oldUser->getBlock() ) {
			$warnings[] = [
				'renameuser-warning-currentblock',
				SpecialPage::getTitleFor( 'Log', 'block' )->getFullURL( [ 'page' => $oldName ] )
			];
		}
		$this->getHookRunner()->onRenameUserWarning( $oldName, $newName, $warnings );
		return $warnings;
	}

	private function showForm( $oldName, $newName, $warnings, $reason, $moveChecked, $suppressChecked ) {
		$performer = $this->getUser();

		$formDescriptor = [
			'oldusername' => [
				'type' => 'user',
				'name' => 'oldusername',
				'label-message' => 'renameuserold',
				'default' => $oldName,
				'required' => true,
			],
			'newusername' => [
				'type' => 'text',
				'name' => 'newusername',
				'label-message' => 'renameusernew',
				'default' => $newName,
				'required' => true,
			],
			'reason' => [
				'type' => 'text',
				'name' => 'reason',
				'label-message' => 'renameuserreason',
				'maxlength' => CommentStore::COMMENT_CHARACTER_LIMIT,
				'maxlength-unit' => 'codepoints',
				'infusable' => true,
				'default' => $reason,
				'required' => true,
			],
		];

		if ( $this->permissionManager->userHasRight( $performer, 'move' ) ) {
			$formDescriptor['confirm'] = [
				'type' => 'check',
				'id' => 'movepages',
				'name' => 'movepages',
				'label-message' => 'renameusermove',
				'default' => $moveChecked,
			];
		}
		if ( $this->permissionManager->userHasRight( $performer, 'suppressredirect' ) ) {
			$formDescriptor['suppressredirect'] = [
				'type' => 'check',
				'id' => 'suppressredirect',
				'name' => 'suppressredirect',
				'label-message' => 'renameusersuppress',
				'default' => $suppressChecked,
			];
		}

		if ( $warnings ) {
			$warningsHtml = [];
			foreach ( $warnings as $warning ) {
				$warningsHtml[] = is_array( $warning ) ?
					$this->msg( $warning[0] )->params( array_slice( $warning, 1 ) )->parse() :
					$this->msg( $warning )->parse();
			}

			$formDescriptor['renameuserwarnings'] = [
				'type' => 'info',
				'label-message' => 'renameuserwarnings',
				'raw' => true,
				'rawrow' => true,
				'default' => new FieldLayout(
					new MessageWidget( [
						'label' => new HtmlSnippet(
							'<ul><li>'
							. implode( '</li><li>', $warningsHtml )
							. '</li></ul>'
						),
						'type' => 'warning',
					] )
				),
			];

			$formDescriptor['confirmaction'] = [
				'type' => 'check',
				'name' => 'confirmaction',
				'id' => 'confirmaction',
				'label-message' => 'renameuserconfirm',
			];
		}

		$htmlForm = HTMLForm::factory( 'ooui', $formDescriptor, $this->getContext() )
			->setMethod( 'post' )
			->setId( 'renameuser' )
			->setSubmitTextMsg( 'renameusersubmit' );

		$this->getOutput()->addHTML( $htmlForm->prepareForm()->getHTML( false ) );
	}

	/**
	 * @param string $search Prefix to search for
	 * @param int $limit Maximum number of results to return (usually 10)
	 * @param int $offset Number of results to skip (usually 0)
	 * @return string[] Matching subpages
	 */
	public function prefixSearchSubpages( $search, $limit, $offset ) {
		$user = $this->userFactory->newFromName( $search );
		if ( !$user ) {
			return [];
		}
		return $this->userNamePrefixSearch->search( 'public', $search, $limit, $offset );
	}

	protected function getGroupName() {
		return 'users';
	}
}
