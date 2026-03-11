<?php

namespace Telepedia\Extensions\TelepediaCore\Rabbit;

use JobQueue;
use RunnableJob;

class JobQueueRabbitMQ extends JobQueue {

	/**
	 * @inheritDoc
	 */
	protected function supportedOrders() {
		// TODO: Implement supportedOrders() method.
	}

	/**
	 * @inheritDoc
	 */
	protected function optimalOrder() {
		// TODO: Implement optimalOrder() method.
	}

	/**
	 * @inheritDoc
	 */
	protected function doIsEmpty() {
		// TODO: Implement doIsEmpty() method.
	}

	/**
	 * @inheritDoc
	 */
	protected function doGetSize() {
		// TODO: Implement doGetSize() method.
	}

	/**
	 * @inheritDoc
	 */
	protected function doGetAcquiredCount() {
		// TODO: Implement doGetAcquiredCount() method.
	}

	/**
	 * @inheritDoc
	 */
	protected function doBatchPush( array $jobs, $flags ) {
		// TODO: Implement doBatchPush() method.
	}

	/**
	 * @inheritDoc
	 */
	protected function doPop() {
		// TODO: Implement doPop() method.
	}

	/**
	 * @inheritDoc
	 */
	protected function doAck( RunnableJob $job ) {
		// TODO: Implement doAck() method.
	}

	/**
	 * @inheritDoc
	 */
	public function getAllQueuedJobs() {
		// TODO: Implement getAllQueuedJobs() method.
	}
}
