<?php
$timer_start = time();

set_time_limit(0);
class Mht2Html {
  static $READ_LENGTH = 102400;
  static $STR_BOUNDARY_PREFIX = 'boundary="';
  static $STR_CONTENT_TYPE = 'Content-Type:';
  static $STR_CONTENT_LOCATION = 'Content-Location:';
  static $STR_LINE_BREAK = "\r\n";

  // output image dir
  public $outputDir = __DIR__ . './html';

  // file path
  public $file;
  public $fileSize;

  // file stream for the mht file
  protected $stream;

  // boundaryStr
  protected $boundaryStr;
  protected $contentPos;
  // content parts
  protected $parts;

  public $imageFiles;
  public $textFiles;

  public $statics;

  /**
   * Constructor
   *
   * @param $file
   * Path of the mht file to be processed
   *
   * Directory of the image output, should be writable
   */
  public function __construct($file = null) {
    if(!$file) {
      if(php_sapi_name() === 'cli') {
        echo 'Please input file name: ';
        $file = trim(fgets(STDIN));
      }
    }
    $this->loadFile($file);
  }
  public function __destruct() {
    @fclose($this->stream);
  }

  public function loadFile($file) {
    if(is_file($file) && is_readable($file)) {
      $this->file = realpath($file);
      $this->stream = fopen($file, 'r');
      $this->fileSize = filesize($this->file);

      $this->boundaryStr = $this->getBoundary();
      if(!$this->boundaryStr) {
        throw new Exception('Incorrect file format: Boundary string not found!');
      }
    }
    else {
      throw new Exception('File doesn\'t exist or is in wrong format');
    }
    $dirAvailable = true;
    if(!is_dir($this->outputDir)) {
      $dirAvailable = mkdir($this->outputDir);
    }
    if(!is_writable(realpath($this->outputDir)) || !$dirAvailable) {
      throw new Exception('Output directory isne\'t writable');
    }
  }

  /**
   * Get boundary string
   *
   * @return bondary string or false
   */
  protected function getBoundary() {
    fseek($this->stream, 0);
    while(!feof($this->stream)) {
      $line = trim(fgets($this->stream));
      if(($pos = strpos($line, self::$STR_BOUNDARY_PREFIX)) !== FALSE) {
        while('' === ($line = trim(fgets($this->stream)))) {
        }

        $this->contentPos = ftell($this->stream);
        return $line;
      }
    }
    return false;
  }
  /**
   * Get the content MIME parts (positions)
   */
  protected function getParts() {
    // keep the variable name short
    $fp = &$this->stream;
    // content count
    $i = 0;
    // next reading pos (just after the boundary string)
    $nextStart = $this->contentPos;
    while(true) {
      // jump to next content block
      fseek($fp, $nextStart);
      // start position of the current reading trunk, not the start of the content block
      $startPos = $nextStart;
      while(true) {
        // read content block
        $content = fread($fp, self::$READ_LENGTH);
        // looped to end of file, break the outer while
        if($this->fileSize - ftell($fp) < 10 && empty($content)) break 2;

        $pos = strpos($content, $this->boundaryStr);
        // boundary string not found
        if($pos === false) {
          // set back the file pointer to the length of boundary string, in case the current $content string contains half of the boundary string
          fseek($fp, - strlen($this->boundaryStr), SEEK_CUR);
          $startPos = ftell($fp);
        }
        // boundary string found
        else {
          fseek($fp, $nextStart);
          // get content meta and find the start offset of the real content
          while($line = trim(fgets($fp))) {
            // check block content type
            $posType = stripos($line, self::$STR_CONTENT_TYPE);
            if($posType !== false) {
              $posType += strlen(self::$STR_CONTENT_TYPE);
              // get content type and format
              list($this->parts[$i]['type'], $this->parts[$i]['format']) = explode('/', trim(substr($line, $posType)));
              continue;
            }

            // check image file name
            $posFileName = stripos($line, self::$STR_CONTENT_LOCATION);
            if($posFileName !== false) {
              $posFileName += strlen(self::$STR_CONTENT_LOCATION);
              $this->parts[$i]['image_file'] = trim(substr($line, $posFileName));
            }
          }
          $this->parts[$i]['start'] = ftell($fp);
          // find the next start
          fseek($fp, $startPos + $pos);
          fgets($fp);
          $nextStart = ftell($fp);

          // stripe line endings
          fseek($fp, $startPos + $pos - 20);
          $strEmpty = fread($fp, 20);
          $lenEmpty = strlen($strEmpty) - strlen(rtrim($strEmpty));
          $this->parts[$i]['end'] = $startPos + $pos - $lenEmpty;

          break;
        }
      }
      $i++;
    }
  }
  /**
   * Parse the file
   */
  public function parse() {
    $this->getParts();
    // keep the variable name short
    $fp = &$this->stream;
    $imageNameOld = array();
    $imageNameNew = array();
    // write images to disk
    foreach($this->parts as $i => $part) {
      // processing image
      if($part['type'] == 'image' && !array_search($part['image_file'], $imageNameOld)) {
        fseek($fp, $part['start']);
        $oldFilePath = realpath($this->outputDir) . DIRECTORY_SEPARATOR . $part['image_file'];
        $whandle = fopen($oldFilePath, 'wb');
        stream_filter_append($whandle, 'convert.base64-decode', STREAM_FILTER_WRITE);
        stream_copy_to_stream($fp, $whandle, $part['end'] - $part['start']);
        fclose($whandle);
        $md5FileName = md5_file($oldFilePath) . '.' . str_replace('jpeg', 'jpg', $part['format']);
        $imageNameOld[] = $part['image_file'];
        $imageNameNew[] = $md5FileName;
        $newFilePath = realpath($this->outputDir) . DIRECTORY_SEPARATOR . $md5FileName;
        if(file_exists($newFilePath)) {
          unlink($oldFilePath);
        }
        else {
          rename($oldFilePath, $newFilePath);
        }
        $this->imageFiles[] = $md5FileName;
      }
    }
    // output html file

    foreach($this->parts as $i => $part) {
      // processing html
      if($part['type'] == 'text') {

        $newFilePath = realpath($this->outputDir) . DIRECTORY_SEPARATOR . 'text' . $i . '.' . $part['format'];

        $whandle = fopen($newFilePath, 'w');

        if($part['format'] == 'html') {
          // go to the first line of the file
          fseek($fp, $part['start']);

          $charsLeft = $part['end'] - ftell($fp);
          while($charsLeft > 0) {
            //if($charsLeft <= self::$READ_LENGTH) $lastRead = true;
            $content = fread($fp, min(self::$READ_LENGTH, $charsLeft));
            $charsLeft = $part['end'] - ftell($fp);

            // if there's no line ending, then loop to read next block to get at least a single line of content
            while(true) {
              // last occurrence of line break, to cut the string for an entire line
              $pos = strrpos($content, self::$STR_LINE_BREAK);
              if($pos === false) {
                $content .= fread($fp, min(self::$READ_LENGTH, $charsLeft));
                $charsLeft = $part['end'] - ftell($fp);
              }
              if($pos !== false || $charsLeft == 0) {
                break;
              }
            }

            // ignore the last half line
            if($pos && $charsLeft > self::$READ_LENGTH) {
              $contentLen = $pos;
              fseek($fp, -(strlen($content) - $pos), SEEK_CUR);
              $content = substr($content, 0, $pos);
            }

            //$this->saveMessage($content);
            $content = str_replace($imageNameOld, $imageNameNew, $content);
            fwrite($whandle, $content);
          }

          fclose($whandle);
        }
        else {
          fseek($fp, $part['start']);
          stream_copy_to_stream($fp, $whandle, $part['end'] - $part['start']);
          fclose($whandle);
          
        }

        $this->textFiles[] = basename($newFilePath);
      }
    }
  }

  function saveMessage(&$content) {

  }
}

$qmp = new Mht2Html('test.mht');
$qmp->parse();

echo PHP_EOL, 'Time elapsed: ', time() - $timer_start, PHP_EOL, 'Memory usage:', memory_get_peak_usage(), PHP_EOL;