<?php

namespace MediaWiki\Extension\AspaklaryaImages;

use PackedHoverImageGallery as GlobalPackedHoverImageGallery;

class PackedHoverImageGallery extends GlobalPackedHoverImageGallery {
    public function removeImage( $index ) {
        array_splice( $this->mImages, $index, 1 );
    }
}