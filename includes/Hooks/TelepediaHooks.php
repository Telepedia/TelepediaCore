<?php

namespace Telepedia\Extensions\TelepediaCore\Hooks;

use MediaWiki\MediaWikiServices;
use MediaWiki\User\User;
use Telepedia\ConfigCentre\Wiki;
use Telepedia\Extensions\CreateWiki\Hooks\CreateWikiNewWikiHook;
use Throwable;

class TelepediaHooks implements CreateWikiNewWikiHook {

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

		$services = MediaWikiServices::getInstance();
		$httpRequestFactory = $services->getHttpRequestFactory();

		try {
			$req = $httpRequestFactory->create(
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
}