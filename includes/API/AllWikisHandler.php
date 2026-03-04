<?php

namespace Telepedia\Extensions\TelepediaCore\API;

use MediaWiki\Api\ApiBase;
use MediaWiki\Api\ApiQuery;
use MediaWiki\Api\ApiQueryBase;
use Telepedia\ConfigCentre\Settings\ConfigCentreVariableService;
use Telepedia\ConfigCentre\WikiDataService;
use Wikimedia\ParamValidator\ParamValidator;

class AllWikisHandler extends ApiQueryBase {

	public function __construct(
		ApiQuery $query,
		string $moduleName,
		private readonly WikiDataService $wikiDataService,
		private readonly ConfigCentreVariableService $variableService,
	) {
		parent::__construct( $query, $moduleName, 'aw' );
	}

	/**
	 * @return void
	 * @throws \MediaWiki\Api\ApiUsageException
	 */
	public function execute(): void {
		$params = $this->extractRequestParams();
		$continue = $params['continue'] ?? null;

		$data = $this->wikiDataService->loadAllWithPagination( true, $continue );
		$total = $data['total'];
		$pages = $data['pages'];
		$perPage = $data['perPage'];
		$nextContinue = $data['continue'] ?? null;
		$wikis = $data['wikis'] ?? [];

		$wikiData = [];

		foreach ( $wikis as $wiki ) {
			$logo = $this->variableService->getValueByKey( '$wgLogo', $wiki->getWikiId() );
			$wikiData[] = [
				'sitename' => $wiki->getSitename(),
				'url' => $wiki->getUrl(),
				'logo' => $logo,
			];
		}

		$returnData = [
			'total' => $total,
			'pages' => $pages,
			'perPage' => $perPage,
			'wikis' => $wikiData,
		];

		if ( $nextContinue !== null ) {
			$this->setContinueEnumParameter( 'continue', $nextContinue );
		}

		$this->getResult()->addValue(
			'query',
			$this->getModuleName(),
			$returnData
		);
	}

	public function getAllowedParams(): array {
		return [
			'continue' => [
				ParamValidator::PARAM_TYPE => 'integer',
				ParamValidator::PARAM_REQUIRED => false,
				ApiBase::PARAM_HELP_MSG => 'api-help-param-continue',
			]
		];
	}

	/**
	 * @inheritDoc
	 */
	public function getCacheMode( $params ): string {
		return 'public';
	}
}
