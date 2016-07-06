<?php

namespace Frasmage\Mediable;

use Frasmage\Mediable\Exceptions\MediaUrlException;
use Frasmage\Mediable\Exceptions\MediaMoveException;
use Glide;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Storage;

/**
 * Media Model
 *
 * @author Sean Fraser <sean@plankdesign.com>
 *
 * @var string $basename
 */
class Media extends Model
{

    const TYPE_IMAGE = 'image';
    const TYPE_IMAGE_VECTOR = 'vector';
    const TYPE_PDF = 'pdf';
    const TYPE_VIDEO = 'video';
    const TYPE_AUDIO = 'audio';
    const TYPE_ARCHIVE = 'archive';
    const TYPE_DOCUMENT = 'document';
    const TYPE_SPREADSHEET = 'spreadsheer';
    const TYPE_OTHER = 'other';
    const TYPE_ALL = 'all';

    protected $guarded = ['id', 'disk', 'directory', 'filename', 'extension', 'size', 'mime', 'type'];

    private $url_generator;

    /**
     * {@inheritDoc}
     */
    public static function boot()
    {
        parent::boot();

        //remove file on deletion
        static::deleted(function ($media) {
            $media->storage()->delete($media->getDiskPath());
        });
    }

    /**
     * Retrieve all associated models of given class
     * @param  string $class FQCN
     * @return \Illuminate\Database\Eloquent\Relations\MorphToMany
     */
    public function models($class)
    {
        return $this->morphedByMany($class, 'mediable')->withPivot('tag');
    }

    /**
     * Retrieve the file extension
     * @return string
     */
    public function getBasenameAttribute()
    {
        return $this->filename . '.' . $this->extension;
    }

    /**
     * Query scope for to find media in a particular directory
     * @param  Builder  $q
     * @param  string  $disk      Filesystem disk to search in
     * @param  string  $directory Path relative to disk
     * @param  boolean $recursive (_optional_) If true, will find media in or under the specified directory
     * @return void
     */
    public function scopeInDirectory(Builder $q, $disk, $directory, $recursive = false)
    {
        $q->where('disk', $disk);
        if ($recursive) {
            $directory = str_replace(['%', '_'], ['\%', '\_'], $directory);
            $q->where('directory', 'like', $directory.'%');
        } else {
            $q->where('directory', '=', $directory);
        }
    }

    /**
     * Query scope for finding media in a particular directory or one of its subdirectories
     * @param  Builder  $q
     * @param  string  $disk      Filesystem disk to search in
     * @param  string  $directory Path relative to disk
     * @return void
     */
    public function scopeInOrUnderDirectory(Builder $q, $disk, $directory)
    {
        $q->inDirectory($disk, $directory, true);
    }

    /**
     * Query scope for finding media by basename
     * @param  Builder $q
     * @param  string  $basename filename and extension
     * @return void
     */
    public function scopeWhereBasename(Builder $q, $basename)
    {
        $q->where('filename', pathinfo($basename, PATHINFO_FILENAME))
            ->where('extension', pathinfo($basename, PATHINFO_EXTENSION));
    }

    /**
     * Query scope finding media at a path relative to a disk
     * @param  Builder $q
     * @param  string  $disk
     * @param  string  $path directory, filename and extension
     * @return void
     */
    public function scopeForPathOnDisk(Builder $q, $disk, $path)
    {
        $q->where('disk', $disk)
            ->where('directory', pathinfo($path, PATHINFO_DIRNAME))
            ->where('filename', pathinfo($path, PATHINFO_FILENAME))
            ->where('extension', pathinfo($path, PATHINFO_EXTENSION));
    }

    /**
     * Calculate the file size in human readable byte notation
     * @param  integer $precision (_optional_) Number of decimal places to include.
     * @return string
     */
    public function readableSize($precision = 1)
    {
        if ($this->size === 0) {
            return '0 B';
        }
        static $units = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];
        $exponent = floor(log($this->size, 1024));
        $value = $this->size / pow(1024, $exponent);
        return round($value, $precision) . ' ' . $units[$exponent];
    }

    /**
     * Get the path to the file relative to the root of the disk
     * @return string
     */
    public function getDiskPath(){
        return ltrim(rtrim($this->directory, '/') . '/' . ltrim($this->basename, '/'), '/');
    }

    /**
     * Get the absolute filesystem path to the file
     * @return string
     */
    public function getAbsolutePath()
    {
        return $this->getUrlGenerator()->getAbsolutePath();
    }

    /**
     * Check if the file is located below the public webroot
     * @return boolean
     */
    public function isPubliclyAccessible()
    {
        return $this->getUrlGenerator()->isPubliclyAccessible();
    }

    /**
     * Get the absolute URL to the media file
     * @throws MediaUrlException If media's disk is not publicly accessible
     * @return string
     */
    public function getUrl()
    {
        return $this->getUrlGenerator()->getUrl();
    }

    /**
     * Check if the file exists on disk
     * @return boolean
     */
    public function fileExists()
    {
        return $this->storage()->has($this->getDiskPath());
    }

    /**
     * Move the file to a new location on disk
     *
     * Will invoke the `save()` method on the model after the associated file has been moved to prevent synchronization errors
     * @param  string $destination directory relative to disk root
     * @param  string $name        filename. Do not include extension
     * @return void
     * @throws  MediaMoveException If attempting to change the file extension or a file with the same name already exists at the destination
     */
    public function move($destination, $filename = null)
    {
        if ($filename) {
            $filename = $this->removeExtensionFromFilename($filename);
        } else {
            $filename = $this->filename;
        }

        $destination = trim($destination, DIRECTORY_SEPARATOR);
        $target_path = $destination . DIRECTORY_SEPARATOR . $filename . '.' . $this->extension;

        if ($this->storage()->has($target_path)) {
            throw MediaMoveException::destinationExists($target_path);
        }

        $this->storage()->move($this->getDiskPath(), $target_path);
        $this->filename = $filename;
        $this->directory = $destination;
        $this->save();
    }

    /**
     * Rename the file in place
     * @param  string $name
     * @return void
     * @see Media::move()
     */
    public function rename($filename)
    {
        $this->move($this->directory, $filename);
    }

    /**
     * Retrieve the contents of the file
     * @return string
     */
    public function contents()
    {
        return $this->storage()->get($this->getDiskPath());
    }

    /**
     * Get the filesystem object for this media
     * @return \Illuminate\Contracts\Filesystem\Filesystem
     */
    protected function storage()
    {
        return app('filesystem')->disk($this->disk);
    }

    /**
     * Get a UrlGenerator instance for the media.
     * @return \Frasmage\Mediable\UrlGenerators\UrlGenerator
     */
    protected function getUrlGenerator(){
        return app('mediable.url.factory')->create($this);
    }

    /**
     * Remove the media's extension from a filename
     * @param  string $filename
     * @return string
     */
    private function removeExtensionFromFilename($filename)
    {
        $extension = '.' . $this->extension;
        $extension_length = mb_strlen($filename) - mb_strlen($extension);
        if (mb_strrpos($filename, $extension) === $extension_length) {
            $filename = mb_substr($filename, 0, $extension_length);
        }
        return $filename;
    }
}
