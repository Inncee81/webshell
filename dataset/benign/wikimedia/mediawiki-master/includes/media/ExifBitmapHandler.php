<?php
/**
 * Handler for bitmap images with exif metadata.
 *
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
 * @ingroup Media
 */

/**
 * Stuff specific to JPEG and (built-in) TIFF handler.
 * All metadata related, since both JPEG and TIFF support Exif.
 *
 * @ingroup Media
 */
class ExifBitmapHandler extends BitmapHandler {
	/** Error extracting metadata */
	public const BROKEN_FILE = '-1';

	/** Outdated error extracting metadata */
	public const OLD_BROKEN_FILE = '0';

	public function convertMetadataVersion( $metadata, $version = 1 ) {
		// basically flattens arrays.
		$version = intval( explode( ';', $version, 2 )[0] );
		if ( $version < 1 || $version >= 2 ) {
			return $metadata;
		}

		$avoidHtml = true;

		if ( !is_array( $metadata ) ) {
			$metadata = unserialize( $metadata );
		}
		if ( !isset( $metadata['MEDIAWIKI_EXIF_VERSION'] ) || $metadata['MEDIAWIKI_EXIF_VERSION'] != 2 ) {
			return $metadata;
		}

		// Treat Software as a special case because in can contain
		// an array of (SoftwareName, Version).
		if ( isset( $metadata['Software'] )
			&& is_array( $metadata['Software'] )
			&& is_array( $metadata['Software'][0] )
			&& isset( $metadata['Software'][0][0] )
			&& isset( $metadata['Software'][0][1] )
		) {
			$metadata['Software'] = $metadata['Software'][0][0] . ' (Version '
				. $metadata['Software'][0][1] . ')';
		}

		$formatter = new FormatMetadata;

		// ContactInfo also has to be dealt with specially
		if ( isset( $metadata['Contact'] ) ) {
			$metadata['Contact'] =
				$formatter->collapseContactInfo(
					$metadata['Contact'] );
		}

		foreach ( $metadata as &$val ) {
			if ( is_array( $val ) ) {
				$val = $formatter->flattenArrayReal( $val, 'ul', $avoidHtml );
			}
		}
		$metadata['MEDIAWIKI_EXIF_VERSION'] = 1;

		return $metadata;
	}

	/**
	 * @param File $image
	 * @param string $metadata
	 * @return bool|int
	 */
	public function isMetadataValid( $image, $metadata ) {
		global $wgShowEXIF;
		if ( !$wgShowEXIF ) {
			# Metadata disabled and so an empty field is expected
			return self::METADATA_GOOD;
		}
		if ( $metadata === self::OLD_BROKEN_FILE ) {
			# Old special value indicating that there is no Exif data in the file.
			# or that there was an error well extracting the metadata.
			wfDebug( __METHOD__ . ": back-compat version" );

			return self::METADATA_COMPATIBLE;
		}
		if ( $metadata === self::BROKEN_FILE ) {
			return self::METADATA_GOOD;
		}
		Wikimedia\suppressWarnings();
		$exif = unserialize( $metadata );
		Wikimedia\restoreWarnings();
		if ( !isset( $exif['MEDIAWIKI_EXIF_VERSION'] )
			|| $exif['MEDIAWIKI_EXIF_VERSION'] != Exif::version()
		) {
			if ( isset( $exif['MEDIAWIKI_EXIF_VERSION'] )
				&& $exif['MEDIAWIKI_EXIF_VERSION'] == 1
			) {
				// back-compatible but old
				wfDebug( __METHOD__ . ": back-compat version" );

				return self::METADATA_COMPATIBLE;
			}
			# Wrong (non-compatible) version
			wfDebug( __METHOD__ . ": wrong version" );

			return self::METADATA_BAD;
		}

		return self::METADATA_GOOD;
	}

	/**
	 * @param File $image
	 * @param bool|IContextSource $context Context to use (optional)
	 * @return array|bool
	 */
	public function formatMetadata( $image, $context = false ) {
		$meta = $this->getCommonMetaArray( $image );
		if ( count( $meta ) === 0 ) {
			return false;
		}

		return $this->formatMetadataHelper( $meta, $context );
	}

	public function getCommonMetaArray( File $file ) {
		$metadata = $file->getMetadata();
		if ( $metadata === self::OLD_BROKEN_FILE
			|| $metadata === self::BROKEN_FILE
			|| $this->isMetadataValid( $file, $metadata ) === self::METADATA_BAD
		) {
			// So we don't try and display metadata from PagedTiffHandler
			// for example when using InstantCommons.
			return [];
		}

		$exif = unserialize( $metadata );
		if ( !$exif ) {
			return [];
		}
		unset( $exif['MEDIAWIKI_EXIF_VERSION'] );

		return $exif;
	}

	public function getMetadataType( $image ) {
		return 'exif';
	}

	/**
	 * Wrapper for base classes ImageHandler::getImageSize() that checks for
	 * rotation reported from metadata and swaps the sizes to match.
	 *
	 * @param File|FSFile $image
	 * @param string $path
	 * @return array|false
	 */
	public function getImageSize( $image, $path ) {
		$gis = parent::getImageSize( $image, $path );

		// Don't just call $image->getMetadata(); FSFile::getPropsFromPath() calls us with a bogus object.
		// This may mean we read EXIF data twice on initial upload.
		if ( $this->autoRotateEnabled() ) {
			$meta = $this->getMetadata( $image, $path );
			$rotation = $this->getRotationForExif( $meta );
		} else {
			$rotation = 0;
		}

		if ( $rotation == 90 || $rotation == 270 ) {
			$width = $gis[0];
			$gis[0] = $gis[1];
			$gis[1] = $width;
		}

		return $gis;
	}

	/**
	 * On supporting image formats, try to read out the low-level orientation
	 * of the file and return the angle that the file needs to be rotated to
	 * be viewed.
	 *
	 * This information is only useful when manipulating the original file;
	 * the width and height we normally work with is logical, and will match
	 * any produced output views.
	 *
	 * @param File $file
	 * @return int 0, 90, 180 or 270
	 */
	public function getRotation( $file ) {
		if ( !$this->autoRotateEnabled() ) {
			return 0;
		}

		$data = $file->getMetadata();

		return $this->getRotationForExif( $data );
	}

	/**
	 * Given a chunk of serialized Exif metadata, return the orientation as
	 * degrees of rotation.
	 *
	 * @param string $data
	 * @return int 0, 90, 180 or 270
	 * @todo FIXME: Orientation can include flipping as well; see if this is an issue!
	 */
	protected function getRotationForExif( $data ) {
		if ( !$data ) {
			return 0;
		}
		Wikimedia\suppressWarnings();
		$data = unserialize( $data );
		Wikimedia\restoreWarnings();
		if ( isset( $data['Orientation'] ) ) {
			# See http://sylvana.net/jpegcrop/exif_orientation.html
			switch ( $data['Orientation'] ) {
				case 8:
					return 90;
				case 3:
					return 180;
				case 6:
					return 270;
				default:
					return 0;
			}
		}

		return 0;
	}
}
