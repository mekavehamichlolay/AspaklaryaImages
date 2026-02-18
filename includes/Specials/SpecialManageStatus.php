<?php

namespace MediaWiki\Extension\AspaklaryaImages\Specials;

use MediaWiki\Exception\ErrorPageError;
use MediaWiki\Exception\PermissionsError;
use MediaWiki\Exception\UserBlockedError;
use MediaWiki\Extension\AspaklaryaImages\Constants;
use MediaWiki\Extension\AspaklaryaImages\File;
use MediaWiki\FileRepo\File\File as FileRepoFile;
use MediaWiki\Html\Html;
use MediaWiki\HTMLForm\HTMLForm;
use MediaWiki\Logging\LogEventsList;
use MediaWiki\Logging\LogPage;
use MediaWiki\Permissions\PermissionManager;
use MediaWiki\SpecialPage\UnlistedSpecialPage;
use MediaWiki\Title\Title;
use Wikimedia\ObjectCache\WANObjectCache;
use Wikimedia\Rdbms\ILoadBalancer;

class SpecialManageStatus extends UnlistedSpecialPage {
    
    private ?Title $title;
    private ?File $file;
    private ILoadBalancer $loadBalancer;
    private WANObjectCache $cache;
    private PermissionManager $permissionManager;
	private bool $submitClicked;
    private bool $wasSaved = false;
    private const SALT ='aspaklaryaimages';
    
    public function __construct( ILoadBalancer $loadBalancer, WANObjectCache $cache, PermissionManager $permissionManager ) {
        parent::__construct( 'ManageFileStatus', Constants::RESTRICTION );
        $this->loadBalancer = $loadBalancer;
        $this->cache = $cache;
        $this->permissionManager = $permissionManager;
        $this->title = null;
        $this->file = null;
    }

    public function execute( $par ) {
        $this->useTransactionalTimeLimit();
        $this->checkReadOnly();
        $this->checkPermissions();
        $out = $this->getOutput();
        $user = $this->getUser();
        $request = $this->getRequest();
        $this->setHeaders();
        $this->outputHeader();
        $this->submitClicked = $request->wasPosted() && $request->getCheck( 'wpSubmit' );
        $titleText = '';
        if ( $par ) {
            $titleText = $par;  
        } else {
            $titleText = trim( $request->getText( 'title' ) );
        }
        if ( !$titleText ) {
            throw new ErrorPageError( 'ai-manage-status-no-title-title', 'ai-manage-status-no-title-text' );
        }
        $this->title = FileRepoFile::normalizeTitle( $titleText );
        if ( !$this->title || !$this->title->canExist() ) {
            throw new ErrorPageError( 'ai-manage-status-invalid-title-title', 'ai-manage-status-invalid-title-text' );
        }
        if ( $this->permissionManager->isBlockedFrom( $user, $this->title, !$this->submitClicked ) ) {
            throw new UserBlockedError(
				// @phan-suppress-next-line PhanTypeMismatchArgumentNullable Block is checked and not null
				$user->getBlock(),
				$user,
				$this->getLanguage(),
				$request->getIP()
			);
        }
        $this->file = new File( $this->loadBalancer, $this->cache, $this->title );
        if ( $this->submitClicked && $user->authorizeAction( Constants::RESTRICTION ) ) {
            $this->handleFormSubmit();
        } else {
            $this->showForm();
        }

        $logs = new LogPage( Constants::LOG_NAME );
        $out->addHTML( "<h2>" . $logs->getName()->escaped() . "</h2>\n" );
        LogEventsList::showLogExtract(
            $out,
            Constants::LOG_NAME,
            $this->title,
            '', /* user */
            [ 'lim' => 25, 'useMaster' => $this->wasSaved ]
        );

    }

    private function showForm(){
        $out = $this->getOutput();
        $out->addWikiMsg( 'ai-manage-status-summary' );
       
        $fields = [];
        $fields[] = [
				'type' => 'hidden',
				'name' => 'title',
				'default' => $this->title->getText(),
		];
        $netfreeRadio = [
			'type' => 'radio',
			'label-raw' => $this->msg( 'ai-manage-status-netfree-text' )->escaped(),
			'id' => 'wpNetfree',
			'flatlist' => true,
			'name' => 'wpNetfree',
            'default' => '',
		];
        
        foreach ( Constants::NETFREE_OPTIONS as $index => $option ) {
            $netfreeRadio[ 'options-messages' ][ 'ai-manage-status-netfree-' . $option || 'leave' ] = $index;
        }
        if ( $this->wasSaved ) {
            $netfreeRadio['default'] = array_flip( Constants::NETFREE_OPTIONS )[ Constants::getTextOptionFromBool( $this->file->getNetfreeStatus() ) ];
        }
        $fields[] = $netfreeRadio;
        $authorizedRadio = [
        	'type' => 'radio',
			'label-raw' => $this->msg( 'ai-manage-status-authorized-text' )->escaped(),
			'id' => 'wpAuthorized',
			'flatlist' => true,
			'name' => 'wpAuthorized',
            'default' => '',
		];
        foreach ( Constants::AUTHORIZED_OPTIONS as $index => $option ) {
            $authorizedRadio[ 'options-messages' ][ 'ai-manage-status-authorized-' . $option || 'leave' ] = $index;
        }
        
        if ( $this->wasSaved ) {
            $authorizedRadio['default'] = array_flip( Constants::AUTHORIZED_OPTIONS )[ Constants::getTextOptionFromBool( $this->file->getAuthorizedStatus() ) ];
        }
        $fields[] = $authorizedRadio;
        $htmlForm = HTMLForm::factory( 'ooui', $fields, $this->getContext() );
        $htmlForm
            ->setSubmitText( $this->msg( 'ai-status-submit' )->text() )
            ->setSubmitName( 'wpSubmit' )
            ->setWrapperLegend( $this->msg( 'ai-status-legend' )->text() )
            ->setAction( $this->getPageTitle()->getLocalURL( [ 'action' => 'submit' ] ) )
            ->setTokenSalt( [ self::SALT, $this->getPageTitle()->getPrefixedDBkey() ] )
            ->prepareForm();
        $out->addHTML( $htmlForm->getHTML( false ) );
    }

    private function handleFormSubmit() {
        $token = $this->getRequest()->getVal( 'wpEditToken' );
		if ( $this->submitClicked && !$this->getContext()->getCsrfTokenSet()->matchToken( $token, [ self::SALT, $this->getPageTitle()->getPrefixedDBkey() ] ) ) {
			$this->getOutput()->addWikiMsg( 'sessionfailure' );

			return false;
		}

        $netfreeStatus = $this->getRequest()->getInt( 'wpNetfree' );
        $authorizedStatus = $this->getRequest()->getInt( 'wpAuthorized' );

        if ( $netfreeStatus === 0 && $authorizedStatus === 0 ) {
            $this->noChange();
            return;
        }
        if ( !$this->getUser()->authorizeAction( Constants::RESTRICTION ) ) {
            throw new PermissionsError( Constants::RESTRICTION );
        }
        if ( $netfreeStatus < 0 || $netfreeStatus > count( Constants::NETFREE_OPTIONS ) ) {
            throw new ErrorPageError( 'ai-manage-status-invalid-netfree-title', 'ai-manage-status-invalid-netfree-text' );
        }
        if ( $authorizedStatus < 0 || $authorizedStatus > count( Constants::AUTHORIZED_OPTIONS ) ) {
            throw new ErrorPageError( 'ai-manage-status-invalid-authorized-title', 'ai-manage-status-invalid-authorized-text' );
        }
        $netfreeStatus = Constants::NETFREE_OPTIONS[ $netfreeStatus ];
        $authorizedStatus = Constants::AUTHORIZED_OPTIONS[ $authorizedStatus ];
        $changed = false;
        if ( $netfreeStatus !== '' ) {
            $netfreeCurrent = Constants::getTextOptionFromBool( $this->file->getNetfreeStatus() );
            if ( $netfreeStatus !== $netfreeCurrent ) {
                if ( $netfreeStatus === 'none' ) {
                    $this->file->deleteNetfreeStatus();
                } else {
                    $this->file->setNetfreeStatus( $netfreeStatus === 'open' );
                }
                $changed = true;
            }
        }
        if ( $authorizedStatus !== '' ) {
            $authorizedCurrent = Constants::getTextOptionFromBool( $this->file->getAuthorizedStatus() );
            if ( $authorizedStatus !== $authorizedCurrent ) {
                if ( $authorizedStatus === 'none' ) {
                    $this->file->deleteAuthorizedStatus();
                } else {
                    $this->file->setAuthorizedStatus( $authorizedStatus === 'open' );
                }
                $changed = true;
            }
        }
        if ( !$changed ) {
            $this->noChange();
            return;
        }
        $status = $this->file->updateStatus( $this->getUser() );
        if ( $status->isOK() ) {
            $this->success();
        } else {
            $this->failure( $status );
        }
    }

    protected function noChange() {
        $out = $this->getOutput();
        $out->setPageTitleMsg( $this->msg( 'actionnochange' ) );
        $this->showForm();
    }
    /**
	 * Report that the submit operation succeeded
	 */
	protected function success() {
		$out = $this->getOutput();
		$out->setPageTitleMsg( $this->msg( 'actioncomplete' ) );
		$out->addHTML(
			Html::successBox(
				$out->msg( 'ai-status-change-success' )->parse()
			)
		);
		$this->wasSaved = true;
        $this->file->loadStatusBits( false );
		$this->showForm();
	}

	/**
	 * Report that the submit operation failed
	 * @param Status $status
	 */
	protected function failure( $status ) {
		$out = $this->getOutput();
		$out->setPageTitleMsg( $this->msg( 'actionfailed' ) );
		$out->addHTML(
			Html::errorBox(
				$out->parseAsContent(
					$status->getWikiText( 'ai-status-change-failure', false, $this->getLanguage() )
				)
			)
		);
		$this->showForm();
	}

    public function doesWrites() {
		return true;
	}

	public function getRestriction() {
		return Constants::RESTRICTION;
	}

    protected function getGroupName() {
		return 'pagetools';
	}

} 
    