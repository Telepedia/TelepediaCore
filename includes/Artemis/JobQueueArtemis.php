<?php

namespace Telepedia\Extensions\TelepediaCore\Artemis;

use ArrayIterator;
use Exception;
use JobQueue;
use MediaWiki\MediaWikiServices;
use RunnableJob;

class JobQueueArtemis extends JobQueue {

	/**
	 * @inheritDoc
	 */
	protected function supportedOrders(): array {
		return [ 'fifo' ];
	}

	/**
	 * @inheritDoc
	 */
	protected function supportsDelayedJobs(): bool {
		return true;
	}

	/**
	 * @inheritDoc
	 */
	protected function optimalOrder(): string {
		return 'fifo';
	}

	/**
	 * @inheritDoc
	 */
	protected function doIsEmpty(): bool {
		// no-op for now
		return false;
	}

	/**
	 * @inheritDoc
	 */
	protected function doGetSize(): int {
		// no-op for now
		return 0;
	}

	/**
	 * @inheritDoc
	 */
	protected function doGetAcquiredCount(): int {
		// no-op for now
		return 0;
	}

	/**
	 * Much of this is adapted from the JobQueueRedis::class from Core, albeit with a few changes
	 * @inheritDoc
	 * @throws Exception
	 */
	protected function doBatchPush( array $jobs, $flags ) {
		/** @var ArtemisFactory $artemisFactory */
		$artemisFactory = MediaWikiServices::getInstance()->get( 'ArtemisFactory' );
		$client = $artemisFactory->getClient();

		$queue = '/queue/mediawiki.jobs.incoming';

		$items = [];
		foreach ( $jobs as $job ) {
			$jobSpec = [
				'type'       => $job->getType(),
				'namespace'  => $job->getParams()['namespace'] ?? NS_SPECIAL,
				'title'      => $job->getParams()['title'] ?? '',
				'params'     => $job->getParams(),
				'rtimestamp' => $job->getReleaseTimestamp() ?: 0,
				'uuid'       => $this->idGenerator->newRawUUIDv4(),
				'sha1'       => $job->ignoreDuplicates()
					? \Wikimedia\base_convert( sha1( serialize( $job->getDeduplicationInfo() ) ), 16, 36, 31 )
					: '',
				'timestamp'  => time(),
				'wiki'       => $this->getDomain(),
			];

			$key = $jobSpec['sha1'] !== '' ? $jobSpec['sha1'] : $jobSpec['uuid'];
			$items[$key] = $jobSpec;
		}

		if ( $items === [] ) {
			// nought to be done
			return;
		}

		foreach ( $items as $item ) {
			$headers = [
				'persistent' => 'true',
				'content-type' => 'application/json',
				'receipt' => $jobSpec['uuid']
			];

			// if the job is delayed, tell Artemis to hold the job hostage
			// until then
			$releaseTimestamp = $jobSpec['rtimestamp'];
			if ( $releaseTimestamp > 0 ) {
				$delayMs = max( 0, ( $releaseTimestamp - time() ) * 1000 );
				if ( $delayMs > 0 ) {
					$headers['AMQ_SCHEDULED_DELAY'] = (string)$delayMs;
				}
			}

			try {
				$client->send( $queue, json_encode( $jobSpec), $headers );
			} catch ( Exception $e ) {
				// not sure this job queue group here is the best, but alas
				wfDebugLog( 'runJobs', 'Failed to push job to Artemis: ' . $e->getMessage() );
				throw $e;
			}
		}
	}

	/**
	 * @inheritDoc
	 */
	protected function doPop() {
		// no-op at present
		return false;
	}

	/**
	 * @inheritDoc
	 */
	protected function doAck( RunnableJob $job ) {
		// no-op at present
	}

	/**
	 * @inheritDoc
	 */
	public function getAllQueuedJobs(): ArrayIterator {
		// no-op at present
		return new ArrayIterator( [] );
	}
}
