<?php

/**
 * Represents an Image
 *
 * @package framework
 * @subpackage filesystem
 */
class Image extends File {
	
	const ORIENTATION_SQUARE = 0;
	const ORIENTATION_PORTRAIT = 1;
	const ORIENTATION_LANDSCAPE = 2;
	
	private static $backend = "GDBackend";
	
	private static $casting = array(
		'Tag' => 'HTMLText',
	);

	/**
	 * @config
	 * @var int The width of an image thumbnail in a strip.
	 */
	private static $strip_thumbnail_width = 50;
	
	/**
	 * @config
	 * @var int The height of an image thumbnail in a strip.
	 */
	private static $strip_thumbnail_height = 50;
	
	/**
	 * @config
	 * @var int The width of an image thumbnail in the CMS.
	 */
	private static $cms_thumbnail_width = 100;
	
	/**
	 * @config
	 * @var int The height of an image thumbnail in the CMS.
	 */
	private static $cms_thumbnail_height = 100;
	
	/**
	 * @config
	 * @var int The width of an image thumbnail in the Asset section.
	 */
	private static $asset_thumbnail_width = 100;
	
	/**
	 * @config
	 * @var int The height of an image thumbnail in the Asset section.
	 */
	private static $asset_thumbnail_height = 100;
	
	/**
	 * @config
	 * @var int The width of an image preview in the Asset section.
	 */
	private static $asset_preview_width = 400;
	
	/**
	 * @config
	 * @var int The height of an image preview in the Asset section.
	 */
	private static $asset_preview_height = 200;
	
	public static function set_backend($backend) {
		self::$backend = $backend;
	}
	
	public static function get_backend() {
		return self::$backend;
	}
	
	/**
	 * Set up template methods to access the transformations generated by 'generate' methods.
	 */
	public function defineMethods() {
		$methodNames = $this->allMethodNames();
		foreach($methodNames as $methodName) {
			if(substr($methodName,0,8) == 'generate') {
				$this->addWrapperMethod(substr($methodName,8), 'getFormattedImage');
			}
		}
		
		parent::defineMethods();
	}

	public function getCMSFields() {
		$fields = parent::getCMSFields();

		$urlLink = "<div class='field readonly'>";
		$urlLink .= "<label class='left'>"._t('AssetTableField.URL','URL')."</label>";
		$urlLink .= "<span class='readonly'><a href='{$this->Link()}'>{$this->RelativeLink()}</a></span>";
		$urlLink .= "</div>";
		
		//attach the addition file information for an image to the existing FieldGroup create in the parent class
		$fileAttributes = $fields->fieldByName('Root.Main.FilePreview')->fieldByName('FilePreviewData');
		$fileAttributes->push(new ReadonlyField("Dimensions", _t('AssetTableField.DIM','Dimensions') . ':'));

		return $fields;
	}

	/**
	 * An image exists if it has a filename.
	 * Does not do any filesystem checks.
	 * 
	 * @return boolean
	 */
	public function exists() {
		if(isset($this->record["Filename"])) {
			return true;
		}		
	}
	
	/**
	 * Return an XHTML img tag for this Image,
	 * or NULL if the image file doesn't exist on the filesystem.
	 * 
	 * @return string
	 */
	public function getTag() {
		if(file_exists(Director::baseFolder() . '/' . $this->Filename)) {
			$url = $this->getURL();
			$title = ($this->Title) ? $this->Title : $this->Filename;
			if($this->Title) {
				$title = Convert::raw2att($this->Title);
			} else {
				if(preg_match("/([^\/]*)\.[a-zA-Z0-9]{1,6}$/", $title, $matches)) {
					$title = Convert::raw2att($matches[1]);
				}
			}
			return "<img src=\"$url\" alt=\"$title\" />";
		}
	}
	
	/**
	 * Return an XHTML img tag for this Image.
	 * 
	 * @return string
	 */
	public function forTemplate() {
		return $this->getTag();
	}

	/**
	 * File names are filtered through {@link FileNameFilter}, see class documentation
	 * on how to influence this behaviour.
	 *
	 * @deprecated 3.2
	 */
	public function loadUploadedImage($tmpFile) {
		Deprecation::notice('3.2', 'Use the Upload::loadIntoFile()');

		if(!is_array($tmpFile)) {
			user_error("Image::loadUploadedImage() Not passed an array.  Most likely, the form hasn't got the right"
				. "enctype", E_USER_ERROR);
		}
		
		if(!$tmpFile['size']) {
			return;
		}
		
		$class = $this->class;

		// Create a folder		
		if(!file_exists(ASSETS_PATH)) {
			mkdir(ASSETS_PATH, Config::inst()->get('Filesystem', 'folder_create_mask'));
		}
		
		if(!file_exists(ASSETS_PATH . "/$class")) {
			mkdir(ASSETS_PATH . "/$class", Config::inst()->get('Filesystem', 'folder_create_mask'));
		}

		// Generate default filename
		$nameFilter = FileNameFilter::create();
		$file = $nameFilter->filter($tmpFile['name']);
		if(!$file) $file = "file.jpg";
		
		$file = ASSETS_PATH . "/$class/$file";
		
		while(file_exists(BASE_PATH . "/$file")) {
			$i = $i ? ($i+1) : 2;
			$oldFile = $file;
			$file = preg_replace('/[0-9]*(\.[^.]+$)/', $i . '\\1', $file);
			if($oldFile == $file && $i > 2) user_error("Couldn't fix $file with $i", E_USER_ERROR);
		}
		
		if(file_exists($tmpFile['tmp_name']) && copy($tmpFile['tmp_name'], BASE_PATH . "/$file")) {
			// Remove the old images

			$this->deleteFormattedImages();
			return true;
		}
	}
	
	/**
	 * Resize the image by preserving aspect ratio, keeping the image inside the
	 * $width and $height
	 * 
	 * @param integer $width The width to size within
	 * @param integer $height The height to size within
	 * @return Image
	 */
	public function SetRatioSize($width, $height) {
		
		// Prevent divide by zero on missing/blank file
		if(empty($this->width) || empty($this->height)) return null;
		
		// Check if image is already sized to the correct dimension
		$widthRatio = $width / $this->width;
		$heightRatio = $height / $this->height;
		if( $widthRatio < $heightRatio ) {
			// Target is higher aspect ratio than image, so check width
			if($this->isWidth($width)) return $this;
		} else {
			// Target is wider aspect ratio than image, so check height
			if($this->isHeight($height)) return $this;
		}
		
		// Item must be regenerated
		return  $this->getFormattedImage('SetRatioSize', $width, $height);
	}
	
	/**
	 * Resize the image by preserving aspect ratio, keeping the image inside the
	 * $width and $height
	 * 
	 * @param Image_Backend $backend
	 * @param integer $width The width to size within
	 * @param integer $height The height to size within
	 * @return Image_Backend
	 */
	public function generateSetRatioSize(Image_Backend $backend, $width, $height) {
		return $backend->resizeRatio($width, $height);
	}
	
	/**
	 * Resize this Image by width, keeping aspect ratio. Use in templates with $SetWidth.
	 * 
	 * @param integer $width The width to set
	 * @return Image
	 */
	public function SetWidth($width) {
		return $this->isWidth($width) 
			? $this
			: $this->getFormattedImage('SetWidth', $width);
	}
	
	/**
	 * Resize this Image by width, keeping aspect ratio. Use in templates with $SetWidth.
	 * 
	 * @param Image_Backend $backend
	 * @param type $width The width to set
	 * @return Image_Backend
	 */
	public function generateSetWidth(Image_Backend $backend, $width) {
		return $backend->resizeByWidth($width);
	}
	
	/**
	 * Resize this Image by height, keeping aspect ratio. Use in templates with $SetHeight.
	 * 
	 * @param integer $height The height to set
	 * @return Image
	 */
	public function SetHeight($height) {
		return $this->isHeight($height)
			? $this 
			: $this->getFormattedImage('SetHeight', $height);
	}
	
	/**
	 * Resize this Image by height, keeping aspect ratio. Use in templates with $SetHeight.
	 * 
	 * @param Image_Backend $backend
	 * @param integer $height The height to set
	 * @return Image_Backend
	 */
	public function generateSetHeight(Image_Backend $backend, $height){
		return $backend->resizeByHeight($height);
	}
	
	/**
	 * Resize this Image by both width and height, using padded resize. Use in templates with $SetSize.
	 * @see Image::PaddedImage()
	 * 
	 * @param integer $width The width to size to
	 * @param integer $height The height to size to
	 * @return Image
	 */
	public function SetSize($width, $height) {
		return $this->isSize($width, $height)
			? $this 
			: $this->getFormattedImage('SetSize', $width, $height);
	}
	
	/**
	 * Resize this Image by both width and height, using padded resize. Use in templates with $SetSize.
	 * 
	 * @param Image_Backend $backend
	 * @param integer $width The width to size to
	 * @param integer $height The height to size to
	 * @return Image_Backend
	 */
	public function generateSetSize(Image_Backend $backend, $width, $height) {
		return $backend->paddedResize($width, $height);
	}
	
	public function CMSThumbnail() {
		return $this->getFormattedImage('CMSThumbnail');
	}
	
	/**
	 * Resize this image for the CMS. Use in templates with $CMSThumbnail.
	 * @return Image_Backend
	 */
	public function generateCMSThumbnail(Image_Backend $backend) {
		return $backend->paddedResize($this->stat('cms_thumbnail_width'),$this->stat('cms_thumbnail_height'));
	}
	
	/**
	 * Resize this image for preview in the Asset section. Use in templates with $AssetLibraryPreview.
	 * @return Image_Backend
	 */
	public function generateAssetLibraryPreview(Image_Backend $backend) {
		return $backend->paddedResize($this->stat('asset_preview_width'),$this->stat('asset_preview_height'));
	}
	
	/**
	 * Resize this image for thumbnail in the Asset section. Use in templates with $AssetLibraryThumbnail.
	 * @return Image_Backend
	 */
	public function generateAssetLibraryThumbnail(Image_Backend $backend) {
		return $backend->paddedResize($this->stat('asset_thumbnail_width'),$this->stat('asset_thumbnail_height'));
	}
	
	/**
	 * Resize this image for use as a thumbnail in a strip. Use in templates with $StripThumbnail.
	 * @return Image_Backend
	 */
	public function generateStripThumbnail(Image_Backend $backend) {
		return $backend->croppedResize($this->stat('strip_thumbnail_width'),$this->stat('strip_thumbnail_height'));
	}
	
	/**
	 * Resize this Image by both width and height, using padded resize. Use in templates with $PaddedImage.
	 * @see Image::SetSize()
	 * 
	 * @param integer $width The width to size to
	 * @param integer $height The height to size to
	 * @param boolean $stretchImage Override default stretch setting
	 * @return Image
	 */
	public function PaddedImage($width, $height, $backgroundColor='FFFFFF', $stretchImage=null) {
		return $this->isSize($width, $height)
			? $this 
			: $this->getFormattedImage('PaddedImage', $width, $height, $backgroundColor, $stretchImage);
	}
	
	/**
	 * Resize this Image by both width and height, using padded resize. Use in templates with $PaddedImage.
	 * 
	 * @param Image_Backend $backend
	 * @param integer $width The width to size to
	 * @param integer $height The height to size to
	 * @param boolean $stretchImage Override default stretch setting
	 * @return Image_Backend
	 */
	public function generatePaddedImage(Image_Backend $backend, $width, $height, $backgroundColor = 'FFFFFF',
										$stretchImage = null) {
		return $backend->paddedResize($width, $height, $backgroundColor, $stretchImage);
	}
	
	/**
	 * Determine if this image is of the specified size
	 * 
	 * @param integer $width Width to check
	 * @param integer $height Height to check
	 * @return boolean
	 */
	public function isSize($width, $height) {
		return $this->isWidth($width) && $this->isHeight($height);
	}
	
	/**
	 * Determine if this image is of the specified width
	 * 
	 * @param integer $width Width to check
	 * @return boolean
	 */
	public function isWidth($width) {
		return !empty($width) && $this->getWidth() == $width;
	}
	
	/**
	 * Determine if this image is of the specified width
	 * 
	 * @param integer $height Height to check
	 * @return boolean
	 */
	public function isHeight($height) {
		return !empty($height) && $this->getHeight() == $height;
	}

	/**
	 * Return an image object representing the image in the given format.
	 * This image will be generated using generateFormattedImage().
	 * The generated image is cached, to flush the cache append ?flush=1 to your URL.
	 * 
	 * Just pass the correct number of parameters expected by the working function
	 * 
	 * @param string $format The name of the format.
	 * @return Image_Cached
	 */
	public function getFormattedImage($format) {
		$args = func_get_args();
		
		if($this->ID && $this->Filename && Director::fileExists($this->Filename)) {
			$cacheFile = call_user_func_array(array($this, "cacheFilename"), $args);
			
			if(!file_exists(Director::baseFolder()."/".$cacheFile) || isset($_GET['flush'])) {
				call_user_func_array(array($this, "generateFormattedImage"), $args);
			}
			
			$cached = new Image_Cached($cacheFile);
			// Pass through the title so the templates can use it
			$cached->Title = $this->Title;
			// Pass through the parent, to store cached images in correct folder.
			$cached->ParentID = $this->ParentID;
			return $cached;
		}
	}
	
	/**
	 * Return the filename for the cached image, given it's format name and arguments.
	 * @param string $format The format name.
	 * @return string
	 */
	public function cacheFilename($format) {
		$args = func_get_args();
		array_shift($args);
		$folder = $this->ParentID ? $this->Parent()->Filename : ASSETS_DIR . "/";
		
		$format = $format.implode('', $args);
		
		return $folder . "_resampled/$format-" . $this->Name;
	}
	
	/**
	 * Generate an image on the specified format. It will save the image
	 * at the location specified by cacheFilename(). The image will be generated
	 * using the specific 'generate' method for the specified format.
	 * 
	 * @param string $format Name of the format to generate.
	 */
	public function generateFormattedImage($format) {
		$args = func_get_args();
		
		$cacheFile = call_user_func_array(array($this, "cacheFilename"), $args);
		
		$backend = Injector::inst()->createWithArgs(self::$backend, array(
			Director::baseFolder()."/" . $this->Filename
		));
		
		if($backend->hasImageResource()) {

			$generateFunc = "generate$format";		
			if($this->hasMethod($generateFunc)){
				
				array_shift($args);
				array_unshift($args, $backend);
					
				$backend = call_user_func_array(array($this, $generateFunc), $args);
				if($backend){
					$backend->writeTo(Director::baseFolder()."/" . $cacheFile);
				}
	
			} else {
				user_error("Image::generateFormattedImage - Image $format public function not found.",E_USER_WARNING);
			}
		}
	}
	
	/**
	 * Generate a resized copy of this image with the given width & height.
	 * Use in templates with $ResizedImage.
	 * 
	 * @param integer $width Width to resize to
	 * @param integer $height Height to resize to
	 * @return Image
	 */
	public function ResizedImage($width, $height) {
		return $this->isSize($width, $height)
			? $this 
			: $this->getFormattedImage('ResizedImage', $width, $height);
	}
	
	/**
	 * Generate a resized copy of this image with the given width & height.
	 * Use in templates with $ResizedImage.
	 * 
	 * @param Image_Backend $backend
	 * @param integer $width Width to resize to
	 * @param integer $height Height to resize to
	 * @return Image_Backend
	 */
	public function generateResizedImage(Image_Backend $backend, $width, $height) {
		if(!$backend){
			user_error("Image::generateFormattedImage - generateResizedImage is being called by legacy code"
				. " or Image::\$backend is not set.",E_USER_WARNING);
		}else{
			return $backend->resize($width, $height);
		}
	}
	
	/**
	 * Generate a resized copy of this image with the given width & height, cropping to maintain aspect ratio.
	 * Use in templates with $CroppedImage
	 * 
	 * @param integer $width Width to crop to
	 * @param integer $height Height to crop to
	 * @return Image
	 */
	public function CroppedImage($width, $height) {
		return $this->isSize($width, $height)
			? $this 
			: $this->getFormattedImage('CroppedImage', $width, $height);
	}

	/**
	 * Generate a resized copy of this image with the given width & height, cropping to maintain aspect ratio.
	 * Use in templates with $CroppedImage
	 * 
	 * @param Image_Backend $backend
	 * @param integer $width Width to crop to
	 * @param integer $height Height to crop to
	 * @return Image_Backend
	 */
	public function generateCroppedImage(Image_Backend $backend, $width, $height) {
		return $backend->croppedResize($width, $height);
	}
	
	/**
	 * Remove all of the formatted cached images for this image.
	 *
	 * @return int The number of formatted images deleted
	 */
	public function deleteFormattedImages() {
		if(!$this->Filename) return 0;
		
		$numDeleted = 0;
		$methodNames = $this->allMethodNames(true);
		$cachedFiles = array();
		
		$folder = $this->ParentID ? $this->Parent()->Filename : ASSETS_DIR . '/';
		$cacheDir = Director::getAbsFile($folder . '_resampled/');
		
		if(is_dir($cacheDir)) {
			if($handle = opendir($cacheDir)) {
				while(($file = readdir($handle)) !== false) {
					// ignore all entries starting with a dot
					if(substr($file, 0, 1) != '.' && is_file($cacheDir . $file)) {
						$cachedFiles[] = $file;
					}
				}
				closedir($handle);
			}
		}
		
		$generateFuncs = array();
		foreach($methodNames as $methodName) {
			if(substr($methodName, 0, 8) == 'generate') {
				$format = substr($methodName, 8);
				$generateFuncs[] = preg_quote($format);
			}
		}
		// All generate functions may appear any number of times in the image cache name.
		$generateFuncs = implode('|', $generateFuncs);
		$pattern = "/^(({$generateFuncs})\d+\-)+" . preg_quote($this->Name) . "$/i";

		foreach($cachedFiles as $cfile) {
			if(preg_match($pattern, $cfile)) {
				if(Director::fileExists($cacheDir . $cfile)) {
					unlink($cacheDir . $cfile);
					$numDeleted++;
				}
			}
		}
		
		return $numDeleted;
	}
	
	/**
	 * Get the dimensions of this Image.
	 * @param string $dim If this is equal to "string", return the dimensions in string form,
	 * if it is 0 return the height, if it is 1 return the width.
	 * @return string|int
	 */
	public function getDimensions($dim = "string") {
		if($this->getField('Filename')) {

			$imagefile = Director::baseFolder() . '/' . $this->getField('Filename');
			if(file_exists($imagefile)) {
				$size = getimagesize($imagefile);
				return ($dim === "string") ? "$size[0]x$size[1]" : $size[$dim];
			} else {
				return ($dim === "string") ? "file '$imagefile' not found" : null;
			}
		}
	}

	/**
	 * Get the width of this image.
	 * @return int
	 */
	public function getWidth() {
		return $this->getDimensions(0);
	}
	
	/**
	 * Get the height of this image.
	 * @return int
	 */
	public function getHeight() {
		return $this->getDimensions(1);
	}
	
	/**
	 * Get the orientation of this image.
	 * @return ORIENTATION_SQUARE | ORIENTATION_PORTRAIT | ORIENTATION_LANDSCAPE
	 */
	public function getOrientation() {
		$width = $this->getWidth();
		$height = $this->getHeight();
		if($width > $height) {
			return self::ORIENTATION_LANDSCAPE;
		} elseif($height > $width) {
			return self::ORIENTATION_PORTRAIT;
		} else {
			return self::ORIENTATION_SQUARE;
		}
	}
	
	protected function onBeforeDelete() {
		parent::onBeforeDelete(); 

		$this->deleteFormattedImages();
	}
}

/**
 * A resized / processed {@link Image} object.
 * When Image object are processed or resized, a suitable Image_Cached object is returned, pointing to the
 * cached copy of the processed image.
 *
 * @package framework
 * @subpackage filesystem
 */
class Image_Cached extends Image {
	
	/**
	 * Create a new cached image.
	 * @param string $filename The filename of the image.
	 * @param boolean $isSingleton This this to true if this is a singleton() object, a stub for calling methods.
	 *                             Singletons don't have their defaults set.
	 */
	public function __construct($filename = null, $isSingleton = false) {
		parent::__construct(array(), $isSingleton);
		$this->ID = -1;
		$this->Filename = $filename;
	}
	
	public function getRelativePath() {
		return $this->getField('Filename');
	}
	
	/**
	 * Prevent creating new tables for the cached record
	 *
	 * @return false
	 */
	public function requireTable() {
		return false;
	}	
	
	/**
	 * Prevent writing the cached image to the database
	 *
	 * @throws Exception
	 */
	public function write($showDebug = false, $forceInsert = false, $forceWrite = false, $writeComponents = false) {
		throw new Exception("{$this->ClassName} can not be written back to the database.");
	}
}
