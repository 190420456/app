<?php

/*
 *    PngCompote, is an simple php script to revert Apple *so called* 
 *    optimizations from iOS (iPhone & iPad) PNGs.
 *    Copyright (C) 2011 Ludovic Landry & Pascal Cans
 *
 *    This program is free software: you can redistribute it and/or modify
 *    it under the terms of the GNU General Public License as published by
 *    the Free Software Foundation, either version 3 of the License, or
 *    (at your option) any later version.
 *
 *    This program is distributed in the hope that it will be useful,
 *    but WITHOUT ANY WARRANTY; without even the implied warranty of
 *    MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *    GNU General Public License for more details.
 *
 *    You should have received a copy of the GNU General Public License
 *    along with this program.  If not, see <http://www.gnu.org/licenses/>.
 * 
 *
 * I made this in a hurry, script might contains errors, can probably produce
 * non compliant pngs, and might not be compatible with php older than 5.3.
 * But contributions are welcome!
 * 
 * 
 * Usage: 
 * 
 * $filename = __DIR__ . '/icon@2X.png';
 * $newFilename = __DIR__ . '/icon@2X.clean.png';
 *   
 * // this will open and parse the png file.
 * $png = new PngFile($filename);
 * 
 * //create a new, more compliant png.
 * $png->revertIphone($newFilename);
 * 
 * 
 */

define('MAGIC_HEADER', b"\x89\x50\x4E\x47\x0D\x0A\x1A\x0A");
define('CGBI_CHUNK_TYPE', 'CgBI');
define('IHDR_CHUNK_TYPE', 'IHDR');
define('IDAT_CHUNK_TYPE', 'IDAT');
define('IEND_CHUNK_TYPE', 'IEND');
define('CHUNK_SIZE_LENGHT', 4);
define('CHUNK_TYPE_LENGHT', 4);
define('CHUNK_CRC_LENGHT', 4);

class PngFile {
    public $isIphone;
    public $handle;
    public $width = 0;
    public $height = 0;
    public $chunks = array();

    public function __construct($handle) {
        $this->handle = $handle;
        $magic = fread($this->handle, 8);
        if (!strcmp($magic, MAGIC_HEADER) == 0) {
            die('It is NOT a png file.<br/>'.PHP_EOL);
        }

        try {
            $idx = ftell($this->handle);
            do {
                $chunk = new PngChunk($this, $idx);
                //$chunk->dumpInfo();
                $this->chunks[] = $chunk;
                $idx = $chunk->getNextChunkIdx();
            } while (strcmp($chunk->type, IEND_CHUNK_TYPE) != 0);

        } catch (UnexpectedValueException $e) {
            echo ('Error, a problem occured: ' . $e->getMessage() .'<br/>'.PHP_EOL);
        }

    }

    /**
     * https://gist.github.com/juban/8397183
     * http://www.axelbrz.com.ar/?mod=iphone-png-images-normalizer
     * @param $newFilename
     */
    function revertIphone($newFilename)
    {
        $data = '';
        fseek($this->handle, 0);
        while (!feof($this->handle)) {
            $data .= fgets($this->handle);
        };

        $pngheader = "\x89PNG\r\n\x1a\n";
        if (substr($data, 0, 8) != $pngheader) return;

        $dataRes = substr($data, 0, 8);
        $chunkPos = 8;
        $idatData = '';
        $breakLoop = false;

        while ($chunkPos < strlen($data)) {
            $skip = false;

            // Reading chunk
            $chunkLength = unpack("N", substr($data, $chunkPos, $chunkPos + 4));
            $chunkLength = array_shift($chunkLength);
            $chunkType = substr($data, $chunkPos + 4, 4);
            $chunkData = substr($data, $chunkPos + 8, $chunkLength);
            $chunkCRC = unpack("N", substr($data, $chunkPos + $chunkLength + 8, 4));
            $chunkCRC = array_shift($chunkCRC);
            $chunkPos += $chunkLength + 12;
            // Reading header chunk
            if ($chunkType == 'IHDR') {
                $width = unpack("N", substr($chunkData, 0, 4));
                $width = array_shift($width);
                $height = unpack("N", substr($chunkData, 4, 8));
                $height = array_shift($height);
            }
            // Reading image chunk
            if ($chunkType == 'IDAT') {
                // Store the chunk data for later decompression
                $idatData .= $chunkData;
                $skip = true;
            }

            // Removing CgBI chunk
            if ($chunkType == 'CgBI') {
                $skip = true;
            }

            // Add all accumulated IDATA chunks
            if ($chunkType == 'IEND') {
                $chunkLengthEnd = $chunkLength;
                $chunkDataEnd = $chunkData;
                $chunkCRCEnd = $chunkCRC;

                try {
                    // Uncompressing the image chunk
                    $idatData .= $chunkData;
                    $bufSize = $width * $height * 4 + $height;
                    $chunkData = zlib_decode($idatData, $bufSize);
                } catch (\Exception $exc) {
                    file_put_contents($newFilename, $data);
                    return; // already optimized
                }
                $chunkType = 'IDAT';

                // Swapping red & blue bytes for each pixel
                $newdata = "";
                for ($y = 0; $y < $height; $y++) {
                    $i = strlen($newdata);
                    $newdata .= $chunkData[$i];
                    for ($x = 0; $x < $width; $x++) {
                        $i = strlen($newdata);
                        $newdata .= $chunkData[$i + 2];
                        $newdata .= $chunkData[$i + 1];
                        $newdata .= $chunkData[$i + 0];
                        $newdata .= $chunkData[$i + 3];
                    }
                }

                // Compressing the image chunk
                $chunkData = $newdata;
                $chunkData = zlib_encode($chunkData, ZLIB_ENCODING_DEFLATE);

                $chunkLength = strlen($chunkData);
                $chunkCRC = crc32($chunkType . $chunkData);
                //$chunkCRC = ($chunkCRC + 0x100000000) % 0x100000000;
                $breakLoop = true;
            }

            if (!$skip) {
                $dataRes .= pack("N", $chunkLength);
                $dataRes .= $chunkType;
                $dataRes .= $chunkData;
                $dataRes .= pack("N", $chunkCRC);
            }

            if ($breakLoop) {
                $dataRes .= pack("N", $chunkLengthEnd);
                $dataRes .= 'IEND';
                $dataRes .= $chunkDataEnd;
                $dataRes .= pack("N", $chunkCRCEnd);

                break;
            }
        }

        file_put_contents($newFilename, $dataRes);
    }

    function revertIphone_bak($newFilename) {
        //echo 'new uncrushed png file: '.$newFilename.'<br/>'.PHP_EOL;
        if (!$this->isIphone){
            die("Not an apple png");
        }
        $newHandle = fopen($newFilename, "wb");
        fwrite($newHandle, MAGIC_HEADER);
        foreach ($this->chunks as $chunk) {

            if (strcmp($chunk->type, CGBI_CHUNK_TYPE) != 0) {
                //discard CgBI.

                //echo 'writing chunk: '.$chunk->type.'<br/>'.PHP_EOL;
                if ($chunk->dataLength > 0) {
                    $res = fseek($this->handle,
                        $chunk->idxStart+ CHUNK_TYPE_LENGHT + CHUNK_SIZE_LENGHT,
                        SEEK_SET);
                    if ($res == -1) {
                        throw new UnexpectedValueException();
                    }
                    if (strcmp($chunk->type, IDAT_CHUNK_TYPE) == 0) {
                        $data = fread($this->handle, $chunk->dataLength);
                        //apple doesn't use zlib compressed stream but *raw* deflate.
                        $data = gzinflate($data);
                        $dataRes = '';

                        //line length = filtertype + nb pixel on a line * size of a pixel
                        $scanlinesize = 1 + ($this->width * 4);
                        for ($y = 0; $y < $this->height; $y++)
                        {
                            //filter-type
                            $filterType = $data[$y*$scanlinesize];
                            $dataRes .= $filterType;
                            for ($x = 0; $x < $this->width; $x++)
                            {
                                $pixel = substr($data, ($y*$scanlinesize +1)  + ($x*4), 4);
                                //apple is inverting red and blue
                                $dataRes .= $pixel[2].$pixel[1].$pixel[0].$pixel[3];
                            }
                        }
                        //*real* png need zlib compressed data
                        $data = gzcompress($dataRes,9);

                    } else {
                        $data = fread($this->handle, $chunk->dataLength);
                    }
                } else {
                    //no data
                    $data = '';
                }
                $dataLen = pack('N', mb_strlen($data, '8bit'));
                fwrite($newHandle, $dataLen);
                fwrite($newHandle, $chunk->type);
                fwrite($newHandle, $data);
                $crc = pack('N', crc32($chunk->type . $data));
                fwrite($newHandle, $crc);
            }
        }
        fclose($newHandle);
        return TRUE;
    }
}

class PngChunk
{
    public $png;
    public $idxStart;
    public $type;
    public $dataLength;
    public $crc;

    public function __construct($png, $idx) {
        $this->png = $png;
        $this->idxStart = $idx;
        $res = fseek($this->png->handle, $idx, SEEK_SET);
        if ($res == -1) {
            throw new UnexpectedValueException();
        }
        //read data length
        $val = fread($this->png->handle, CHUNK_SIZE_LENGHT);
        if ($val == FALSE) {
            throw new UnexpectedValueException();
        }
        //4 bytes: always 32 bit, big endian byte order
        $val = unpack('N', $val);
        // dunno why but unamed unpack is found at index 1 and not at 0 inside resulting array...
        $this->dataLength = $val[1];

        //read chunk type
        $val = fread($this->png->handle, CHUNK_TYPE_LENGHT);
        if ($val == FALSE) {
            throw new UnexpectedValueException();
        }
        $this->type = $val;

        //skip chunk data
        $res = fseek($this->png->handle, $this->dataLength, SEEK_CUR);
        if ($res == -1) {
            throw new UnexpectedValueException();
        }

        //read crc
        $val = fread($this->png->handle, CHUNK_CRC_LENGHT);
        if ($val == FALSE) {
            throw new UnexpectedValueException();
        }
        $this->crc = $val;

        //CGBI
        if (strcmp($this->type, CGBI_CHUNK_TYPE) == 0) {
            $this->png->isIphone = TRUE;
        }
        //IHDR
        if (strcmp($this->type, IHDR_CHUNK_TYPE) == 0) {
            $res = fseek($this->png->handle,
                $this->idxStart + CHUNK_SIZE_LENGHT + CHUNK_TYPE_LENGHT,
                SEEK_SET);
            if ($res == -1) {
                throw new UnexpectedValueException();
            }
            //read data
            $val = fread($this->png->handle, $this->dataLength);
            if ($val == FALSE) {
                throw new UnexpectedValueException();
            }
            $val = unpack('Nwidth/Nheight/Cdepth/Ccolor/ccompression/cfilter/Cinterlace', $val);
            $this->png->width = $val['width'];
            $this->png->height = $val['height'];
            $this->png->compression = $val['compression'];
        }
    }

    function getNextChunkIdx() {
        return $this->idxStart + CHUNK_SIZE_LENGHT +
            CHUNK_TYPE_LENGHT + $this->dataLength + CHUNK_CRC_LENGHT;
    }

    function dumpInfo() {
        echo '- Chunk starting at : 0x'.dechex($this->idxStart).' ('.$this->idxStart.')<br/>'.PHP_EOL;
        echo '- Chunk type : '.$this->type.'<br/>'.PHP_EOL;
        echo '- Chunk data length : '.$this->dataLength.'<br/>'.PHP_EOL;
        echo '- Chunk CRC : '.hexdump($this->crc).'<br/>'.PHP_EOL;

        //IHDR
        if (strcmp($this->type, IHDR_CHUNK_TYPE) == 0) {
            $res = fseek($this->png->handle,
                $this->idxStart + CHUNK_SIZE_LENGHT + CHUNK_TYPE_LENGHT,
                SEEK_SET);
            if ($res == -1) {
                throw new UnexpectedValueException();
            }
            //read data
            $val = fread($this->png->handle, $this->dataLength);
            if ($val == FALSE) {
                throw new UnexpectedValueException();
            }
            $val = unpack('Nwidth/Nheight/Cdepth/Ccolor/ccompression/cfilter/Cinterlace', $val);
            echo '- - IHDR data Width: '.$val['width'].'<br/>'.PHP_EOL;
            echo '- - IHDR data Height: '.$val['height'].'<br/>'.PHP_EOL;
            echo '- - IHDR data Bit depth: '.$val['depth'].'<br/>'.PHP_EOL;
            echo '- - IHDR data Color type: '.$val['color'].'<br/>'.PHP_EOL;
            echo '- - IHDR data Compression method: '.$val['compression'].'<br/>'.PHP_EOL;
            echo '- - IHDR data Filter method: '.$val['filter'].'<br/>'.PHP_EOL;
            echo '- - IHDR data Interlace method: '.$val['interlace'].'<br/>'.PHP_EOL;
        }

        //IDAT
        if (strcmp($this->type, IDAT_CHUNK_TYPE) == 0) {
            $res = fseek($this->png->handle,
                $this->idxStart + CHUNK_SIZE_LENGHT + CHUNK_TYPE_LENGHT,
                SEEK_SET);
            if ($res == -1) {
                throw new UnexpectedValueException();
            }
            //read data
            $data = fread($this->png->handle, $this->dataLength);
            if ($data == FALSE) {
                throw new UnexpectedValueException();
            }
            if ($this->png->compression == 0) {
                //read zlib stream
                if ($this->png->isIphone) {
                    //iphone strip header and footer
                    $zlib = $data;
                } else {
                    //compression method 1 byte
                    $comp = ord($data[0]);
                    //additional flag 1 byte
                    $add = ord($data[1]);
                    //data
                    $zlibLen = mb_strlen($data, '8bit') - 6;
                    $zlib = substr($data, 2, $zlibLen);
                    //crc 4 bytes
                    $crc = substr($data, 2 + $zliblen, 4);
                }
                //echo '- - IDAT data : '.hexdump($data).'<br/>'.PHP_EOL;
                //echo '- - IDAT zlib data : '.hexdump($zlib).'<br/>'.PHP_EOL;
                $data = gzinflate($zlib);
                //echo '- - IDAT deflate : '.hexdump($data).'<br/>'.PHP_EOL;
            }
        }
    }
}

function hexdump($data) {
    $hex = '';
    for ($i = 0; $i < strlen($data); $i++)
    {
        $hex .= sprintf("%02X ", ord($data[$i]));
    }
    return $hex;
}

?>
