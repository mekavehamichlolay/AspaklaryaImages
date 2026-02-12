<?php

namespace MediaWiki\Extension\AspaklaryaImages;

use NolinesImageGallery as GlobalNolinesImageGallery;

class NolinesImageGallery extends GlobalNolinesImageGallery {
        public function removeImage( $index ) {
        array_splice( $this->mImages, $index, 1 );
    }
}