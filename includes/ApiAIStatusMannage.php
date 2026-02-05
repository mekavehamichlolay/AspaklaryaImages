<?php

namespace MediaWiki\Extension\AspaklaryaImages;

use MediaWiki\Api\ApiBase;
use MediaWiki\FileRepo\File\File;
use MediaWiki\Permissions\PermissionStatus;
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
			$this->dieWithError( 'apierror-aspaklarya_images-missingparams' );
		}
		if ( count( $this->titles ) > 50 ) {
			$this->dieWithError( 'apierror-aspaklarya_images-param-titles-toolarge' );
		}
		if ( !$this->getAuthority()->isAllowed( Constants::RESTRICTION ) ) {
			$this->dieWithError( 'apierror-aspaklarya_images-manage-status-unauthorized' );
		}
		$netfree = $params['netfree'];
		$authorized = $params['authorized'];
		$files = [];
		$nonsense = [];
		foreach ( $this->titles as $titleText ) {
			$title = File::normalizeTitle( $titleText );
			if ( !$title ) {
				$nonsense[] = $titleText;
				continue;
			}
			$files[] = $title;
		}

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
				ApiBase::PARAM_HELP_MSG => 'apihelp-aspaklarya_images-param-titles',
			],
			'netfree' => [
				ParamValidator::PARAM_DEFAULT => 'none',
				ParamValidator::PARAM_TYPE => Constants::NETFREE_OPTIONS,
				ParamValidator::PARAM_REQUIRED => false,
				ApiBase::PARAM_HELP_MSG => 'apihelp-aspaklarya_images-param-netfree',
			],
			'authorized' => [
				ParamValidator::PARAM_DEFAULT => 'none',
				ParamValidator::PARAM_TYPE => Constants::AUTHORIZED_OPTIONS,
				ParamValidator::PARAM_REQUIRED => false,
				ApiBase::PARAM_HELP_MSG => 'apihelp-aspaklarya_images-param-authorized',
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
	public function getExamples() {
		return [
			'api.php?action=aspaklarya_images_status&token=TOKEN&titles=Example.jpg&authorized=good&netfree=open' => 'apihelp-aspaklarya_images_status-example-1'
		];
	}

	public function getHelpUrls() {
		return [ '' ];
	}

}