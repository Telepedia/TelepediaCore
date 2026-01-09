<?php

namespace Telepedia\Extensions\TelepediaCore\Hooks;

use MediaWiki\Http\HttpRequestFactory;
use MediaWiki\MediaWikiServices;
use MediaWiki\User\User;
use Telepedia\ConfigCentre\Wiki;
use Telepedia\Extensions\CreateWiki\Hooks\CreateWikiNewWikiHook;
use Telepedia\Extensions\RequestToBeForgotten\Hooks\RightToBeForgottenRequestComplete;
use Telepedia\Extensions\RequestToBeForgotten\RTBFRequest;
use Throwable;

class TelepediaHooks implements CreateWikiNewWikiHook, RightToBeForgottenRequestComplete {

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
}