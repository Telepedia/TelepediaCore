<?php

namespace Telepedia\Extensions\TelepediaCore\Rabbit;

use ArrayIterator;
use Exception;
use JobQueue;
use MediaWiki\MediaWikiServices;
use PhpAmqpLib\Message\AMQPMessage;
use RunnableJob;

class JobQueueRabbitMQ extends JobQueue {

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
		/** @var RabbitFactory $rabbitFactory */
		$rabbitFactory = MediaWikiServices::getInstance()->get( 'RabbitFactory' );

		// push all jobs to a single queue, we have persistent workers which will shuffle these into different queues
		// depending on their priority (since MediaWiki cannot know what is deemed a high priority job or not)
		$queue = "mediawiki.jobs.incoming";

		// declare our queue; this will do nothing if the queue is already declared
		$channel = $rabbitFactory->getChannel();
		$channel->queue_declare( $queue, false, true, false, false );

		$items = [];
		foreach ( $jobs as $job ) {
			$jobSpec = [
				'type' => $job->getType(),
				'namespace' => $job->getParams()['namespace'] ?? NS_SPECIAL,
				'title' => $job->getParams()['title'] ?? '',
				'params' => $job->getParams(),
				'rtimestamp' => $job->getReleaseTimestamp() ?: 0,
				'uuid' => $this->idGenerator->newRawUUIDv4(),
				'sha1' => $job->ignoreDuplicates() ? \Wikimedia\base_convert( sha1( serialize( $job->getDeduplicationInfo() ) ), 16, 36, 31 )
					: '',
				'timestamp' => time(),
				'wiki' => $this->getDomain()
			];

			if ( $jobSpec['sha1'] !== '' ) {
				$items[$jobSpec['sha1']] = $jobSpec;
			} else {
				$items[$jobSpec['uuid']] = $jobSpec;
			}

			if ( $items === [] ) {
				// if empty, nothing to do
				return;
			}

			// try and push the jobs into the queue
			foreach ( $items as $jobSpec ) {
				$msg = new AMQPMessage( json_encode( $jobSpec ), [
					'delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT,
					'content_type'  => 'application/json'
				] );

				try {
					$channel->basic_publish( $msg, '', $queue );
				} catch ( Exception $e ) {
					wfDebugLog( 'RabbitMQ', "Failed to push job to RabbitMQ: " . $e->getMessage() );
					throw $e;
				}
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
