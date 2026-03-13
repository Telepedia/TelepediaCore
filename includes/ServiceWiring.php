<?php

namespace Telepedia\Extensions\TelepediaCore;

use MediaWiki\Config\ServiceOptions;
use MediaWiki\MediaWikiServices;
use Telepedia\Extensions\TelepediaCore\Rabbit\RabbitFactory;

return [
	'RabbitFactory' => static function (
		MediaWikiServices $services
	): RabbitFactory {
		return new RabbitFactory(
			new ServiceOptions( RabbitFactory::CONSTRUCTOR_OPTIONS, $services->getMainConfig() ),
		);
	}
];
