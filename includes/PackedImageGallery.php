<?php

namespace MediaWiki\Extension\AspaklaryaImages;

use PackedImageGallery as GlobalPackedImageGallery;

class PackedImageGallery extends GlobalPackedImageGallery {
    public function removeImage( $index ) {
        $this->mImages = array_splice( $this->mImages, $index, 1 );
    }
}