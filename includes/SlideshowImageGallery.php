<?php

namespace MediaWiki\Extension\AspaklaryaImages;

use SlideshowImageGallery as GlobalSlideshowImageGallery;

class SlideshowImageGallery extends GlobalSlideshowImageGallery {
    public function removeImage( $index ) {
        $this->mImages = array_splice( $this->mImages, $index, 1 );
    }
}