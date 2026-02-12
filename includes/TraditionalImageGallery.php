<?php

namespace MediaWiki\Extension\AspaklaryaImages;

use TraditionalImageGallery as GlobalTraditionalImageGallery;

class TraditionalImageGallery extends GlobalTraditionalImageGallery {

    public function removeImage( $index ) {
        array_splice( $this->mImages, $index, 1 );
    }

}