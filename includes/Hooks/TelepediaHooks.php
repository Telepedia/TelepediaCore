<?php

namespace Telepedia\Extensions\TelepediaCore\Hooks;

use HtmlArmor;
use MediaWiki\Cache\Hook\MessageCacheFetchOverridesHook;
use MediaWiki\Hook\GetLocalURL__InternalHook;
use MediaWiki\Hook\InitializeArticleMaybeRedirectHook;
use MediaWiki\Http\HttpRequestFactory;
use MediaWiki\Linker\Hook\HtmlPageLinkRendererEndHook;
use MediaWiki\MainConfigNames;
use MediaWiki\MediaWikiServices;
use MediaWiki\User\User;
use MessageCache;
use Telepedia\ConfigCentre\Wiki;
use Telepedia\Extensions\CreateWiki\Hooks\CreateWikiNewWikiHook;
use Telepedia\Extensions\RequestToBeForgotten\Hooks\RightToBeForgottenRequestComplete;
use Telepedia\Extensions\RequestToBeForgotten\RTBFRequest;
use Throwable;

class TelepediaHooks implements
	CreateWikiNewWikiHook,
	RightToBeForgottenRequestComplete,
	GetLocalURL__InternalHook,
	InitializeArticleMaybeRedirectHook,
	HtmlPageLinkRendererEndHook,
	MessageCacheFetchOverridesHook {

	public function __construct(
		private readonly HttpRequestFactory $requestFactory
	) {}

	/**
	 * Send a notification to Discord on wiki creation
	 * @param Wiki $wiki the wiki that was created
	 * @param string $description the description the user gave when creating the wiki
	 * @param string $domain the domain of the wiki created
	 * @param User $user the user who created the wiki
	 * @return void
	 */
	public function onCreateWikiNewWiki( Wiki $wiki, string $description, string $domain, User $user ): void {
		$config = MediaWikiServices::getInstance()->getConfigFactory()->makeConfig( 'TelepediaCore' );
		$webhookUrl = $config->get( 'DiscordCreateWikiWebhookUrl' );

		if ( $webhookUrl == null ) {
			// can't do anything :(
			return;
		}

		$embed = [
			'title' => '📚 New Wiki Created',
			'color' => hexdec('00AAFF'),
			'fields' => [
				[
					'name' => 'Site Name',
					'value' => $wiki->getSitename(),
					'inline' => true
				],
				[
					'name' => 'Description',
					'value' => $description ?: '_No description provided_',
					'inline' => false
				],
				[
					'name' => 'Created By',
					'value' => $user->getName(),
					'inline' => true
				],
				[
					'name' => 'Wiki ID',
					'value' => $wiki->getWikiId(),
					'inline' => true
				],
				[
					'name' => 'Domain',
					'value' => $domain,
					'inline' => false
				]
			],
			// this won't be the timestamp that the wiki was created, but is a good approximation;
			// just for visibility, so no need to be 100% accurate
			'timestamp' => gmdate('c')
		];

		$payload = [
			'content' => "A new wiki was created",
			'username' => 'Wiki Creation Notifier',
			'embeds' => [ $embed ]
		];

		try {
			$req = $this->requestFactory->create(
				$webhookUrl,
				[
					'method' => 'POST',
					'postData' => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
					'headers' => [ 'Content-Type' => 'application/json' ],
					'timeout' => 5
				]
			);

			$req->setHeader( 'Content-Type', 'application/json' );

			$req->execute();
			$statusCode = $req->getStatus();
			$body = $req->getContent();

			// Discord responds with 204 No Content on success.
			if ( $statusCode !== 204 ) {
				wfDebugLog('TelepediaCore',
					"Discord webhook failed: HTTP $statusCode, body: $body. Payload: " . json_encode( $payload )
				);
			}
		} catch ( Throwable $e ) {
			wfDebugLog('TelepediaCore', 'Exception sending Discord webhook: ' . $e->getMessage());
		}
	}

	/**
	 * Send a notification to Jira when a request to be forgotten has been completed
	 */
	public function onRightToBeForgottenComplete( RTBFRequest $request ): void {
		$config = MediaWikiServices::getInstance()->getConfigFactory()->makeConfig( 'TelepediaCore' );
		$jiraWebhook = $config->get( 'RTBFJiraWebhook' );
		$jiraAccessToken = $config->get( 'RTBFJiraAccessToken' );

		$payload = [
			'originalUsername' => $request->originalUsername,
			'targetUsername'   => $request->targetUsername,
			'requestId'    => $request->id,
			'completedAt'   => $request->finishedAt
		];

		$req = $this->requestFactory->create( $jiraWebhook, [
			'method' => 'POST',
			'postData' => json_encode( $payload ),
			'headers' => [
				'Content-Type' => 'application/json',
				'X-Automation-Webhook-Token' => $jiraAccessToken
			],
			'timeout' => 5
		] );

		$req->setHeader( 'Content-Type', 'application/json' );
		$req->setHeader( 'X-Automation-Webhook-Token', $jiraAccessToken );

		try {

			$req->execute();
			$statusCode = $req->getStatus();
			$body = $req->getContent();

			// Jira responds with status code 200 on success
			if ( $statusCode !== 200 ) {
				wfDebugLog(
					'TelepediaCore',
					"Jira webhook failed: HTTP $statusCode, body: $body. Payload: " . json_encode( $payload )
				);
			}
		} catch ( Throwable $e ) {
			wfDebugLog('TelepediaCore', 'Exception request to be forgotten event to Jira: ' . $e->getMessage());
		}
	}

	/**
	 * Use short urls always, even for internal requests
	 * @param $title
	 * @param $url
	 * @param $query
	 * @return void
	 */
	public function onGetLocalURL__Internal( $title, &$url, $query ): void {
		global $wgArticlePath, $wgScript;

		$key = wfUrlencode( $title->getPrefixedDBkey() );

		if ( $url == "{$wgScript}?title={$key}&{$query}" ) {
			$url = wfAppendQuery(str_replace( '$1', $key, $wgArticlePath ), $query );
		}
	}

	/**
	 * @inheritDoc
	 */
	public function onInitializeArticleMaybeRedirect( $title, $request, &$ignoreRedirect, &$target, &$article ) {
		$title = explode( ':', $title );
		$prefix = strtolower($title[0]);

		if ( count( $title ) < 3 || $prefix !== 'tp' ) {
			return true;
		}

		$wiki = strtolower($title[1]);
		$page = implode(':', array_slice( $title, 2 ) );
		$page = str_replace( ' ', '_', $page );
		$page = urlencode( $page );

		$target = "https://$wiki.telepedia.net/wiki/$page";

		return true;
	}

	/**
	 * Global interwiki for [[tp:$1:$2]] -> https://$1.telepedia.net/wiki/$2
	 * @param $linkRenderer
	 * @param $target
	 * @param $isKnown
	 * @param $text
	 * @param $attribs
	 * @param $ret
	 *
	 * @return true
	 */
	public function onHtmlPageLinkRendererEnd( $linkRenderer, $target, $isKnown, &$text, &$attribs, &$ret ): true {
		$target = (string)$target;
		$tooltip = $target;
		$useText = true;

		$ltarget = strtolower( $target );
		$ltext = strtolower( HtmlArmor::getHtml( $text ) );

		if ($ltarget == $ltext) {
			// Allow link piping, but don't modify $text yet
			$useText = false;
		}

		$target = explode( ':', $target );

		if ( count( $target ) < 2 ) {
			// Not enough parameters for interwiki
			return true;
		}

		if ( $target[0] == '0' ) {
			array_shift( $target );
		}

		$prefix = strtolower( $target[0] );

		if ($prefix != 'tp') {
			// Not interested
			return true;
		}

		$wiki = strtolower( $target[1] );
		$target = array_slice( $target, 2 );
		$target = implode( ':', $target );

		if ( !$useText ) {
			$text = $target;
		}
		if ( $text == '' ) {
			$text = $wiki;
		}

		$target = str_replace( ' ', '_', $target );
		$target = urlencode( $target );
		$linkURL = "https://$wiki.telepedia.net/wiki/$target";

		$attribs = [
			'href' => $linkURL,
			'class' => 'extiw',
			'title' => $tooltip
		];

		return true;
	}

	/**
	 * Override some system messages with our custom versions
	 * @param array $keys
	 * @return void
	 */
	public function onMessageCacheFetchOverrides(array &$keys): void {
		static $keysToOverride = [
			'copyrightwarning',
			'pagetitle',
			'group-staff-member',
			'group-steward-member',
			'group-saber-member',
			'group-staff',
			'vector-night-mode-issue-reporting-notice-url',
			'privacypage',
			"disclaimers"
		];

		$languageCode = MediaWikiServices::getInstance()->getMainConfig()->get( MainConfigNames::LanguageCode );

		$transformationCallback = static function ( string $key, MessageCache $cache ) use ( $languageCode ): string {
			$transformedKey = "telepedia-$key";

			// MessageCache uses ucfirst if ord( key ) is < 128, which is true of all
			// of the above.  Revisit if non-ASCII keys are used.
			$ucKey = ucfirst($key);

			if (
				/*
				 * Override order:
				 * 1. If the MediaWiki:$ucKey page exists, use the key unprefixed
				 * (in all languages) with normal fallback order.  Specific
				 * language pages (MediaWiki:$ucKey/xy) are not checked when
				 * deciding which key to use, but are still used if applicable
				 * after the key is decided.
				 *
				 * 2. Otherwise, use the prefixed key with normal fallback order
				 * (including MediaWiki pages if they exist).
				 */
				$cache->getMsgFromNamespace( $ucKey, $languageCode ) === false
			) {
				return $transformedKey;
			}

			return $key;
		};

		foreach ( $keysToOverride as $key ) {
			$keys[ $key ] = $transformationCallback;
		}
	}
}
