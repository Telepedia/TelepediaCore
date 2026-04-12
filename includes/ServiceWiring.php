<?php

namespace Telepedia\Extensions\TelepediaCore;

use MediaWiki\MediaWikiServices;
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

];
