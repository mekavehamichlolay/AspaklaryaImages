<?php

namespace MediaWiki\Extension\AspaklaryaImages;

use PackedOverlayImageGallery as GlobalPackedOverlayImageGallery;

class PackedOverlayImageGallery extends GlobalPackedOverlayImageGallery {
    public function removeImage( $index ) {
        $this->mImages = array_splice( $this->mImages, $index, 1 );
    }
}