<?php
/**
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
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
 */

namespace MediaWiki\Extension\AspaklaryaImages;

use ImageGalleryBase;
use MediaWiki\SpecialPage\QueryPage;
use MediaWiki\Title\Title;
use Wikimedia\Rdbms\IConnectionProvider;

/**
 * List of file pages which don't have yet a netfree or authorize status
 *
 * @ingroup SpecialPage
 * @author Rob Church <robchur@gmail.com>
 */
class SpecialUnknownImages extends QueryPage {

	public function __construct( IConnectionProvider $dbProvider ) {
		parent::__construct( 'Unknownfiles', Constants::RESTRICTION, true );
		$this->setDatabaseProvider( $dbProvider );
	}

	protected function sortDescending() {
		return false;
	}

	public function isExpensive() {
		return true;
	}

	public function isSyndicated() {
		return false;
	}

    public function isCacheable(){
        return true;
    }
	protected function getOrderFields() {
		return [ 'title' ];
	}

	public function execute( $par ) {
		parent::execute( $par );
	}

    protected function outputResults( $out, $skin, $dbr, $res, $num, $offset ) {
		if ( $num > 0 ) {
			$gallery = ImageGalleryBase::factory( false, $this->getContext() );

			// $res might contain the whole 1,000 rows, so we read up to
			// $num [should update this to use a Pager]
			$i = 0;
			foreach ( $res as $row ) {
				$i++;
				$title = Title::makeTitleSafe( NS_FILE, $row->title );
				if ( $title instanceof Title && $title->inNamespace( NS_FILE ) ) {
					$gallery->add( $title, $this->getCellHtml( $row ), '', '', [], ImageGalleryBase::LOADING_LAZY );
				}
				if ( $i === $num ) {
					break;
				}
			}

			$out->addHTML( $gallery->toHTML() );
		}
	}

	public function getQueryInfo() {
		return [
			'tables' => [ 'imagelinks', Constants::IMAGES_TABLE ],
			'fields' => [
				'title' => 'il_to',
			],
			'conds' => [
				Constants::IMAGE_TABLE_TITLE_FIELD => null,
			],
			'options' => [
				'DISTINCT'
			],
			'join_conds' => [
				Constants::IMAGES_TABLE => [
					'LEFT JOIN',
					"il_to = " . Constants::IMAGE_TABLE_TITLE_FIELD,
				],
			],
		];
	}

	protected function getGroupName() {
		return 'maintenance';
	}

    protected function formatResult( $skin, $result ) {
		return false;
	}

    protected function getCellHtml( $row ) {
        return '';
    }
}

