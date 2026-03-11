<?php

/**
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along
 * with this program; if not, write to the Free Software Foundation, Inc.,
 * 51 Franklin Street, Fifth Floor, Boston, MA 02110-1301, USA.
 * http://www.gnu.org/copyleft/gpl.html
 *
 * @file
 * @author Telepedia Ltd.
 * @copyright 2026 Telepedia Ltd.
 * @ingroup TelepediaCore
 */

namespace Telepedia\Extensions\TelepediaCore\Rabbit;

use Exception;
use MediaWiki\Config\ServiceOptions;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Connection\AMQPStreamConnection;

class RabbitFactory {

	public const CONSTRUCTOR_OPTIONS = [
		'RabbitHost',
		'RabbitPort',
		'RabbitUsername',
		'RabbitPassword',
		'RabbitVhost'
	];

	/**
	 * @var ?AMQPStreamConnection
	 */
	private ?AMQPStreamConnection $connection = null;

	/**
	 * @var ?AMQPChannel
	 */
	private ?AMQPChannel $channel = null;

	public function __construct(
		private readonly ServiceOptions $options
	) {
		$options->assertRequiredOptions( self::CONSTRUCTOR_OPTIONS );
	}

	/**
	 * @throws Exception
	 */
	public function getConnection(): AMQPStreamConnection {
		// if we already have a connection, return it
		if ( $this->connection === null ) {
			$this->connection = new AMQPStreamConnection(
				$this->options->get( 'RabbitHost' ),
				$this->options->get( 'RabbitPort' ),
				$this->options->get( 'RabbitUser' ),
				$this->options->get( 'RabbitPassword' ),
				$this->options->get( 'RabbitVhost' )
			);
			// ensure we close this connection and free up resources when the PHP request dies
			register_shutdown_function( [ $this, 'closeConnections' ] );
		}
		return $this->connection;
	}

	/**
	 * Get a channel that we can push messages through
	 * @return AMQPChannel
	 * @throws Exception
	 */
	public function getChannel(): AMQPChannel {
		if ( $this->channel === null ) {
			$this->channel = $this->getConnection()->channel();
		}
		return $this->channel;
	}

	/**
	 * Close the channel and the connection once the request finishes to free up resources
	 * @return void
	 */
	public function closeConnections(): void {
		try {
			if ( $this->channel !== null && $this->channel->is_open() ) {
				$this->channel->close();
			}
			if ( $this->connection !== null && $this->connection->isConnected() ) {
				$this->connection->close();
			}
		} catch ( Exception $ex ) {
			// Suppress exceptions during shutdown to prevent a blank screen
			// @TODO: log this
		}
	}
}
