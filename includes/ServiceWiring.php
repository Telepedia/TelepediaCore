<?php

namespace Telepedia\Extensions\TelepediaCore;

use MediaWiki\Config\ServiceOptions;
use MediaWiki\MediaWikiServices;
use Telepedia\Extensions\TelepediaCore\Artemis\ArtemisFactory;
use Telepedia\Extensions\TelepediaCore\RenameUser\RenameUserService;

return [

	'RenameUserService' => static function (
		MediaWikiServices $services
	): RenameUserService {
		return new RenameUserService(
			$services->getUserFactory(),
			$services->getConnectionProvider(),
			$services->get( 'UAM.GlobalUserService' ),
			$services->getJobQueueGroupFactory(),
			$services->getUserNameUtils()
		);
	},
  
  'ArtemisFactory' => static function (
		MediaWikiServices $services
	): ArtemisFactory {
		return new ArtemisFactory(
			new ServiceOptions( ArtemisFactory::CONSTRUCTOR_OPTIONS, $services->getMainConfig() ),
		);
	}
];
