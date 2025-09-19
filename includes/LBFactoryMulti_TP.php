<?php

namespace Telepedia\Extensions\TelepediaCore;

use Exception;
use ReflectionMethod;
use ReflectionProperty;
use Telepedia\ConfigCentre\Wiki;
use Wikimedia\Rdbms\ILoadBalancer;
use Wikimedia\Rdbms\LBFactoryMulti;

class LBFactoryMulti_TP extends LBFactoryMulti {

	/**
	 * lookup cache to avoid repeated lookups.
	 * @var array
	 */
	private static $lookupCache = [];

	/**
	 * cached reflection property for performance.
	 * @var ?\ReflectionProperty
	 */
	private static $sectionsByDBProperty = null;

	/**
	 * A cached reflection method for performance.
	 * @var ?ReflectionMethod
	 */
	private static $resolveDomainInstanceMethod = null;

	public function __construct( array $conf ) {
		parent::__construct( $conf );
	}

	/**
	 * @inheritDoc
	 */
	public function getMainLB( $domain = false ): ILoadBalancer {

		if ( self::$resolveDomainInstanceMethod === null ) {
			self::$resolveDomainInstanceMethod = new ReflectionMethod( LBFactoryMulti::class, 'resolveDomainInstance' );
			self::$resolveDomainInstanceMethod->setAccessible( true );
		}

		// we're not interested in overriding this behaviour, but yet again,
		// this method is private
		$domainInstance = self::$resolveDomainInstanceMethod->invoke( $this, $domain );
		$database = $domainInstance->getDatabase();

		// The $sectionsByDB property is private, as is the LBFactoryMulti::getSectionFromDB method,
		// which we need to modify in order to dynamically load on our configuration for the wiki if it isn't
		// found already.
		// Ideally this would be protected https://phabricator.wikimedia.org/T404932, but it isn't and its unclear
		// whether upstream will consider making it so. So we'll have to resort to this unfortunately
		if ( self::$sectionsByDBProperty === null ) {
			self::$sectionsByDBProperty = new ReflectionProperty( LBFactoryMulti::class, 'sectionsByDB' );
			self::$sectionsByDBProperty->setAccessible( true );
		}

		$sections = self::$sectionsByDBProperty->getValue( $this );

		// It's not already set into the sectionsByDB array already,
		// lets look it up.
		if ( !isset( $sections[$database] ) ) {
			$this->populateSectionForDb( $database );
		}

		// should have populated it by now, lets return back to the parent to do its thing
		return parent::getMainLB( $domain );
	}

	/**
	 * Uses ConfigCentre to determine which cluster this wikis database exists on
	 *
	 * @param string $database The database name to look up.
	 */
	private function populateSectionForDb( string $database ): void {
		// Use a simple static cache to avoid hitting the DB for the same wiki multiple times per request.
		if ( isset( self::$lookupCache[$database] ) ) {
			$this->injectSection( $database, self::$lookupCache[$database] );
			return;
		}

		try {

			$wiki = Wiki::loadFromDatabaseName( null, $database );

			if ( $wiki ) {
				$cluster = $wiki->getCluster();
				self::$lookupCache[$database] = $cluster;
				$this->injectSection( $database, $cluster );
			}

			// do nothing if we didn't find it; we will use the default MediaWiki behaviour of using the "DEFAULT"
			// cluster
		} catch ( Exception $e ) {
			// warn but don't throw the error, return back to MediaWiki's LoadBalancing behaviour and allow the default
			// to be used
			wfLogWarning( "Could not look up database section for '$database': " . $e->getMessage() );
		}
	}

	/**
	 * Injects a database->cluster mapping into the parent's private property.
	 *
	 * @param string $database
	 * @param string $section
	 */
	private function injectSection( string $database, string $cluster ): void {
		$clusters = self::$sectionsByDBProperty->getValue( $this );
		$clusters[$database] = $cluster;
		self::$sectionsByDBProperty->setValue( $this, $clusters );
	}
}