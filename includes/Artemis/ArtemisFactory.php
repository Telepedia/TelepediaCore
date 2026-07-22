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

namespace Telepedia\Extensions\TelepediaCore\Artemis;

use Exception;
use MediaWiki\Config\ServiceOptions;
use Stomp\Client;
use Stomp\Exception\StompException;

class ArtemisFactory {

	public const CONSTRUCTOR_OPTIONS = [
		'ArtemisHost',
		'ArtemisPort',
		'ArtemisUsername',
		'ArtemisPassword'
	];

	private ?Client $client = null;

	public function __construct(
		private readonly ServiceOptions $options
	) {
		$options->assertRequiredOptions( self::CONSTRUCTOR_OPTIONS );
	}

	/**
	 * Get a connection to Artemis using STOMP
	 * @throws StompException
	 */
	public function getClient(): Client {
		if ( $this->client === null ) {
			$uri = sprintf(
				'tcp://%s:%s',
				$this->options->get( 'ArtemisHost' ),
				$this->options->get( 'ArtemisPort' )
			);

			$this->client = new Client( $uri );
			$this->client->setLogin(
				$this->options->get( 'ArtemisUsername' ),
				$this->options->get( 'ArtemisPassword' )
			);

			// wait up to 700ms for Artemis to acknowledge that each message
			// was persisted
			$this->client->setReceiptWait( 0.7 );
			$this->client->connect();

			// close the connection and free our resources when the PHP process finishes
			register_shutdown_function( [ $this, 'closeConnection' ] );
		}

		return $this->client;
	}

	/**
	 * Close the connection once the request finishes to free up resources
	 * @return void
	 */
	public function closeConnection(): void {
		try {
			if ( $this->client !== null ) {
				$this->client->disconnect();
				$this->client = null;
			}
		} catch ( Exception $e ) {
			// do nothing for now
			// @TODO: log this
		}
	}
}
