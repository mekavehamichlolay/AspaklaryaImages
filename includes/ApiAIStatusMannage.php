<?php

namespace MediaWiki\Extension\AspaklaryaImages;

use MediaWiki\Api\ApiBase;
use MediaWiki\MediaWikiServices;
use Wikimedia\ParamValidator\ParamValidator;

class ApiAIStatusMannage extends ApiBase {

	/** @var string[] */
	private $titles = [];

	public function execute() {
		$params = $this->extractRequestParams();

		$this->titles = $params['titles'];
		if ( $this->titles !== null && !is_array( $this->titles ) ) {
			$this->titles = explode( '|', $this->titles );
		} elseif ( $this->titles === null ) {
			$this->titles = [];
		}
		$this->titles = array_unique( array_filter( $this->titles ) );
		if ( count( $this->titles ) === 0 ) {
			$this->dieWithError( 'apierror-aspaklaryaimages-missingparams' );
		}
		if ( count( $this->titles ) > 50 ) {
			$this->dieWithError( 'apierror-aspaklaryaimages-toomanyvalues' );
		}
		if ( !$this->getAuthority()->isAllowed( Constants::RESTRICTION ) ) {
			$this->dieWithError( 'apierror-aspaklaryaimages-permissiondenied' );
		}
		$netfree = $params['netfree'];
		$authorized = $params['authorized'];
		$lb = MediaWikiServices::getInstance()->getDBLoadBalancer();
		$cache = MediaWikiServices::getInstance()->getMainWANObjectCache();
		$result = FilesMannager::updateMultiStatus( $lb, $cache, $this->getAuthority(), $this->titles, $netfree, $authorized );
		// if ( $result[0] && !$result[0]->isOK() ) {
		// 	$this->dieWithError( $result[0]->getValue() );
		// }
		$this->getResult()->addValue( null, 'aspaklaryaimages-status', [
			'updated' => $result,
		] );
	}

		/** @inheritDoc */
	public function getAllowedParams() {
		return [
			'titles' => [
				ParamValidator::PARAM_TYPE => 'string',
				ParamValidator::PARAM_ISMULTI => true,
				ParamValidator::PARAM_ISMULTI_LIMIT1 => 25,
				ParamValidator::PARAM_ISMULTI_LIMIT2 => 50,
				ParamValidator::PARAM_REQUIRED => true,
				ApiBase::PARAM_HELP_MSG => 'apihelp-aspaklaryaimages-param-titles',
			],
			'netfree' => [
				ParamValidator::PARAM_DEFAULT => 'none',
				ParamValidator::PARAM_TYPE => Constants::NETFREE_OPTIONS,
				ParamValidator::PARAM_REQUIRED => false,
				ApiBase::PARAM_HELP_MSG => 'apihelp-aspaklaryaimages-param-netfree',
			],
			'authorized' => [
				ParamValidator::PARAM_DEFAULT => 'none',
				ParamValidator::PARAM_TYPE => Constants::AUTHORIZED_OPTIONS,
				ParamValidator::PARAM_REQUIRED => false,
				ApiBase::PARAM_HELP_MSG => 'apihelp-aspaklaryaimages-param-authorized',
			],
			'token' => null,
		];
	}

	public function mustBePosted() {
		return true;
	}

	public function needsToken() {
		return 'csrf';
	}

	public function isWriteMode() {
		return true;
	}

	/**
	 * @inheritDoc
	 */
	public function getExamplesMessages() {
		return [
				'action=aspaklaryaimages-manage-status&titles=Example.jpg&authorized=open&netfree=blocked' => 'apihelp-aspaklaryaimages-manage-status-example-1',
		];
	}
}
