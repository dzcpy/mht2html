<?php
/**
 * A fast memory effecient PHP class to convert MHT file to HTML (and images)
 *
 * NOTICE OF LICENSE
 *
 * Licensed under MIT License. URL: http://opensource.org/licenses/mit-license.html
 *
 * @version    1.0
 * @author     Andy Hu
 * @license    MIT
 * @copyright  (c) 2013, Andy Hu
 * @link       https://github.com/andyhu/mht2html
 */

class MhtToHtml
{
    const READ_LENGTH = 102400;
    const STR_BOUNDARY_PREFIX = 'boundary="';
    const STR_CONTENT_TYPE = 'Content-Type:';
    const STR_CONTENT_LOCATION = 'Content-Location:';
    const STR_LINE_BREAK = "\n";

    // output image dir
    public $outputDir = './html';

    // file path
    public $file;
    // file size
    public $fileSize;
    // extracted image files
    public $imageFiles;
    // extracted text/html files
    public $textFiles;

    // file stream of the mht file
    private $stream;
    // boundary string
    private $boundaryStr;
    // the start pos of the real content
    private $contentPos;
    // content parts
    private $parts;
    // if there's a need to replace the image name to the name with md5 of the image file
    private $replaceImageName = false;
    // map old image name to new ones
    private $imageNameMap;

    /**
     * Constructor
     *
     * @param string $file
     * Path of the mht file to be processed
     *
     * @param string $outputDir
     * Directory of the image output, should be writable
     */
    public function __construct($file = null, $outputDir = null)
    {
        set_time_limit(0);
        if(!$file) {
            if(php_sapi_name() === 'cli') {
                echo 'Please input file name: ';
                $file = trim(fgets(STDIN));
            }
        }
        $this->loadFile($file);
        if($outputDir) {
            $dirAvailable = true;
            if(!is_dir($outputDir)) {
                $dirAvailable = mkdir($outputDir);
            }
            if(!is_writable(realpath($outputDir)) || !$dirAvailable) {
                throw new Exception('Output directory doesn\'t exist or isn\'t writable');
            }
            else {
                $this->outputDir = $outputDir;
            }
        }
    }

    public function setReplaceImageName($check) {
      $this->replaceImageName = (bool)$check;
    }

    /**
     * Load MHT file
     *
     * @param string $file
     * MHT file path
     */
    public function loadFile($file)
    {
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
    }

    /**
     * Get boundary string
     *
     * @return string
     * Return bondary string or false
     */
    private function getBoundary()
    {
        fseek($this->stream, 0);
        while(!feof($this->stream)) {
            $line = trim(fgets($this->stream));
            if(($pos = strpos($line, self::STR_BOUNDARY_PREFIX)) !== FALSE) {
                while('' === ($line = trim(fgets($this->stream)))) {
                }

                $this->contentPos = ftell($this->stream);
                return $line;
            }
        }
        return false;
    }

    public function __destruct()
    {
        @fclose($this->stream);
    }

    /**
     * Parse the file
     */
    public function parse()
    {
        $this->getParts();
        // keep the variable name short
        $fp = &$this->stream;
        $this->imageNameMap = array();
        // write images to disk
        foreach($this->parts as $i => $part) {
            // processing image
            if($part['type'] == 'image' && !isset($this->imageNameMap[$part['image_file']])) {
                $part['image_file'] = str_replace(array('\\'), '/', $part['image_file']);
                fseek($fp, $part['start']);
                $oldFilePath = realpath($this->outputDir) . DIRECTORY_SEPARATOR . $part['image_file'];
                if(!$this->replaceImageName) {
                  if(basename($part['image_file']) != $part['image_file'] && dirname($part['image_file'])) {
                    mkdir(realpath($this->outputDir) . DIRECTORY_SEPARATOR . dirname($part['image_file']), 0777, true);
                  }
                }
                $wfp = fopen($oldFilePath, 'wb');
                stream_filter_append($wfp, 'convert.base64-decode', STREAM_FILTER_WRITE);
                stream_copy_to_stream($fp, $wfp, $part['end'] - $part['start']);
                fclose($wfp);
                if($this->replaceImageName) {
                    $md5FileName = md5_file($oldFilePath) . '.' . str_replace('jpeg', 'jpg', $part['format']);
                    $this->imageNameMap[$part['image_file']] = $md5FileName;
                    $newFilePath = realpath($this->outputDir) . DIRECTORY_SEPARATOR . $md5FileName;
                    if(file_exists($newFilePath)) {
                        unlink($oldFilePath);
                    }
                    else {
                        rename($oldFilePath, $newFilePath);
                    }
                    $imageFileName = $md5FileName;
                }
                else {
                    $imageFileName = $part['image_file'];
                }
                $this->imageFiles[] = $imageFileName;
            }
        }
        // output html file

        foreach($this->parts as $i => $part) {
            // processing html
            if($part['type'] == 'text') {

                $newFilePath = realpath($this->outputDir) . DIRECTORY_SEPARATOR . 'text' . $i . '.' . $part['format'];

                $wfp = fopen($newFilePath, 'w');

                if($part['format'] == 'html') {
                    // go to the first line of the file
                    fseek($fp, $part['start']);

                    $charsLeft = $part['end'] - ftell($fp);
                    while($charsLeft > 0) {
                        $content = fread($fp, min($charsLeft, self::READ_LENGTH));
                        $charsLeft = $part['end'] - ftell($fp);

                        // if there's no line ending, then loop to read next block to get at least a single line of content
                        while(true) {
                            // last occurrence of line break, to cut the string for an entire line
                            $pos = strrpos($content, self::STR_LINE_BREAK);
                            if($pos === false) {
                                $content .= fread($fp, min($charsLeft, self::READ_LENGTH));
                                $charsLeft = $part['end'] - ftell($fp);
                            }
                            if($pos !== false || $charsLeft == 0) {
                                break;
                            }
                        }

                        // ignore the last half line
                        if($pos && $charsLeft > self::READ_LENGTH) {
                            $contentLen = $pos;
                            fseek($fp, -(strlen($content) - $pos), SEEK_CUR);
                            $content = substr($content, 0, $pos);
                        }

                        $this->replaceImageName && $this->replaceImage($content);

                        fwrite($wfp, $content);
                    }

                    fclose($wfp);
                }
                else {
                    fseek($fp, $part['start']);
                    stream_copy_to_stream($fp, $wfp, $part['end'] - $part['start']);
                    fclose($wfp);

                }

                $this->textFiles[] = basename($newFilePath);
            }
        }
    }

    /**
     * Get the content MIME parts (positions)
     */
    private function getParts()
    {
        // keep the variable name short
        $fp = &$this->stream;
        // content parts count
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
                $content = fread($fp, self::READ_LENGTH);
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
                        $posType = stripos($line, self::STR_CONTENT_TYPE);
                        if($posType !== false) {
                            $posType += strlen(self::STR_CONTENT_TYPE);
                            // get content type and format
                            list($this->parts[$i]['type'], $this->parts[$i]['format']) = explode('/', trim(substr($line, $posType)));
                            continue;
                        }

                        // check image file name
                        $posFileName = stripos($line, self::STR_CONTENT_LOCATION);
                        if($posFileName !== false) {
                            $posFileName += strlen(self::STR_CONTENT_LOCATION);
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
     * Replace old image names to new image names in the html
     *
     * @param string &$content
     * HTML content block (text format)
     */
    function replaceImage(&$content)
    {
        foreach($this->imageNameMap as $oldImg => $newImg) {
            if(strpos($content, $oldImg) !== false) {
                $content = preg_replace('/(<img\s+[^>]*?)(["\'])' . preg_quote($oldImg, '/') . '\2/si', '$1"' . $newImg . '"', $content);
            }
        }
    }
}