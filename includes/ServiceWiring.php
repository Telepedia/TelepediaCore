<?php

namespace Telepedia\Extensions\TelepediaCore;

use MediaWiki\Config\ServiceOptions;
use MediaWiki\MediaWikiServices;
use Telepedia\Extensions\TelepediaCore\Artemis\ArtemisFactory;

return [
	'ArtemisFactory' => static function (
		MediaWikiServices $services
	): ArtemisFactory {
		return new ArtemisFactory(
			new ServiceOptions( ArtemisFactory::CONSTRUCTOR_OPTIONS, $services->getMainConfig() ),
		);
	}
];
