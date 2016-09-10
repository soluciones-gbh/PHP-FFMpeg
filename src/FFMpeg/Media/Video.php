<?php

/*
 * This file is part of PHP-FFmpeg.
 *
 * (c) Alchemy <info@alchemy.fr>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace FFMpeg\Media;

use Alchemy\BinaryDriver\Exception\ExecutionFailureException;
use FFMpeg\Coordinate\TimeCode;
use FFMpeg\Filters\Audio\SimpleFilter;
use FFMpeg\Exception\InvalidArgumentException;
use FFMpeg\Exception\RuntimeException;
use FFMpeg\Filters\Video\VideoFilters;
use FFMpeg\Filters\FilterInterface;
use FFMpeg\Format\FormatInterface;
use FFMpeg\Format\ProgressableInterface;
use FFMpeg\Format\AudioInterface;
use FFMpeg\Format\VideoInterface;
use Neutron\TemporaryFilesystem\Manager as FsManager;

class Video extends Audio
{
    /**
     * {@inheritdoc}
     *
     * @return VideoFilters
     */
    public function filters()
    {
        return new VideoFilters($this);
    }

    /**
     * {@inheritdoc}
     *
     * @return Video
     */
    public function addFilter(FilterInterface $filter)
    {
        $this->filters->add($filter);

        return $this;
    }

    public function saveWitCustomConfiguration(FormatInterface $format, $outputPathFile)
    {
        $commands = array('-y', '-i', $this->pathfile);

        $filters = clone $this->filters;
        $commands = $this->driver->getConfiguration()->get('ffmpeg.commands');
        $fs = FsManager::create();
        $fsId = uniqid('ffmpeg-passes');
        $passPrefix = $fs->createTemporaryDirectory(0777, 50, $fsId) . '/' . uniqid('pass-');
        $passes = array();
        $totalPasses = $format->getPasses();

        if (1 > $totalPasses) {
            throw new InvalidArgumentException('Pass number should be a positive value.');
        }

        for ($i = 1; $i <= $totalPasses; $i++) {
            $pass = $commands;

            if ($totalPasses > 1) {
                $pass[] = '-pass';
                $pass[] = $i;
                $pass[] = '-passlogfile';
                $pass[] = $passPrefix;
            }

            $pass[] = $outputPathFile;

            $passes[] = $pass;
        }

        $failure = null;

        foreach ($passes as $pass => $passCommands) {
            try {
                /** add listeners here */
                $listeners = null;

                if ($format instanceof ProgressableInterface) {
                    $listeners = $format->createProgressListener($this, $this->ffprobe, $pass + 1, $totalPasses);
                }

                $this->driver->command($passCommands, false, $listeners);
            } catch (ExecutionFailureException $e) {
                $failure = $e;
                break;
            }
        }

        $fs->clean($fsId);

        if (null !== $failure) {
            throw new RuntimeException('Encoding failed', $failure->getCode(), $failure);
        }

        return $this;
    }

    /**
     * Exports the video in the desired format, applies registered filters.
     *
     * @param FormatInterface $format
     * @param string          $outputPathfile
     *
     * @return Video
     *
     * @throws RuntimeException
     */
    public function save(FormatInterface $format, $outputPathfile)
    {
        $commands = array('-y', '-i', $this->pathfile);

        $filters = clone $this->filters;
        $filters->add(new SimpleFilter($format->getExtraParams(), 10));

        if ($this->driver->getConfiguration()->has('ffmpeg.threads')) {
            $filters->add(new SimpleFilter(array('-threads', $this->driver->getConfiguration()->get('ffmpeg.threads'))));
        }
        if ($format instanceof VideoInterface) {
            if (null !== $format->getVideoCodec()) {
                $filters->add(new SimpleFilter(array('-vcodec', $format->getVideoCodec())));
            }
        }
        if ($format instanceof AudioInterface) {
            if (null !== $format->getAudioCodec()) {
                $filters->add(new SimpleFilter(array('-acodec', $format->getAudioCodec())));
            }
        }

        foreach ($filters as $filter) {
            $commands = array_merge($commands, $filter->apply($this, $format));
        }

        if ($format instanceof VideoInterface) {
            $commands[] = '-b:v';
            $commands[] = $format->getKiloBitrate() . 'k';
            $commands[] = '-refs';
            $commands[] = '6';
            $commands[] = '-coder';
            $commands[] = '1';
            $commands[] = '-sc_threshold';
            $commands[] = '40';
            $commands[] = '-flags';
            $commands[] = '+loop';
            $commands[] = '-me_range';
            $commands[] = '16';
            $commands[] = '-subq';
            $commands[] = '7';
            $commands[] = '-i_qfactor';
            $commands[] = '0.71';
            $commands[] = '-qcomp';
            $commands[] = '0.6';
            $commands[] = '-qdiff';
            $commands[] = '4';
            $commands[] = '-trellis';
            $commands[] = '1';
        }

        if ($format instanceof AudioInterface) {
            if (null !== $format->getAudioKiloBitrate()) {
                $commands[] = '-b:a';
                $commands[] = $format->getAudioKiloBitrate() . 'k';
            }
            if (null !== $format->getAudioChannels()) {
                $commands[] = '-ac';
                $commands[] = $format->getAudioChannels();
            }
        }
        //Temporal fixes for mobile convertion
        //TODO luis implement better way
        $commands = array('-y', '-i', $this->pathfile);
        $commands[] = '-ss';
        $commands[] = '00:00:00.00';
        $commands[] = '-t';
        $commands[] = '00:00:15.00';
        $commands[] = '-acodec';
        $commands[] = 'libfdk_aac';
        $commands[] = '-ar';
        $commands[] = 44100;
        $commands[] = '-ab';
        $commands[] = '64k';
        $commands[] = '-ac';
        $commands[] = 1;
        $commands[] = '-acodec';
        $commands[] = 'copy';
        $commands[] = '-vcodec';
        $commands[] = 'libx264';
        $commands[] = '-level';
        $commands[] = 41;
        $commands[] = '-crf';
        $commands[] = 20;
        $commands[] = '-threads';
        $commands[] = 0;
        $commands[] = '-bufsize';
        $commands[] = '1000k';
        $commands[] = '-maxrate';
        $commands[] = '500k';
        $commands[] = '-b:v';
        $commands[] = '500k';
        $commands[] = '-g';
        $commands[] = 60;
        $commands[] = '-r';
        $commands[] = 30;
        $commands[] = '-s';
        $commands[] = '680x680';
        $commands[] = '-coder';
        $commands[] = 1;
        $commands[] = '-flags';
        $commands[] = '+loop';
        $commands[] = '-cmp';
        $commands[] = '+chroma';
        $commands[] = '-partitions';
        $commands[] = '+parti4x4+partp8x8+partb8x8';
        $commands[] = '-me_method';
        $commands[] = 'umh';
        $commands[] = '-subq';
        $commands[] = 7;
        $commands[] = '-me_range';
        $commands[] = 16;
        $commands[] = '-keyint_min';
        $commands[] = 25;
        $commands[] = '-sc_threshold';
        $commands[] = 40;
        $commands[] = '-i_qfactor';
        $commands[] = 0.71;
        $commands[] = '-rc_eq';
        $commands[] = 'blurCplx^(1-qComp)';
        $commands[] = '-bf';
        $commands[] = '16';
        $commands[] = '-b_strategy';
        $commands[] = 1;
        $commands[] = '-bidir_refine';
        $commands[] = 1;
        $commands[] = '-refs';
        $commands[] = 6;
        $commands[] = '-preset';
        $commands[] = 'veryslow';
        $commands[] = '-movflags';
        $commands[] = '+faststart';

        $fs = FsManager::create();
        $fsId = uniqid('ffmpeg-passes');
        $passPrefix = $fs->createTemporaryDirectory(0777, 50, $fsId) . '/' . uniqid('pass-');
        $passes = array();
        $totalPasses = $format->getPasses();

        if (1 > $totalPasses) {
            throw new InvalidArgumentException('Pass number should be a positive value.');
        }

        for ($i = 1; $i <= $totalPasses; $i++) {
            $pass = $commands;

            if ($totalPasses > 1) {
                $pass[] = '-pass';
                $pass[] = $i;
                $pass[] = '-passlogfile';
                $pass[] = $passPrefix;
            }

            $pass[] = $outputPathfile;

            $passes[] = $pass;
        }

        $failure = null;

        foreach ($passes as $pass => $passCommands) {
            try {
                /** add listeners here */
                $listeners = null;

                if ($format instanceof ProgressableInterface) {
                    $listeners = $format->createProgressListener($this, $this->ffprobe, $pass + 1, $totalPasses);
                }

                $this->driver->command($passCommands, false, $listeners);
            } catch (ExecutionFailureException $e) {
                $failure = $e;
                break;
            }
        }

        $fs->clean($fsId);

        if (null !== $failure) {
            throw new RuntimeException('Encoding failed', $failure->getCode(), $failure);
        }

        return $this;
    }

    /**
     * Gets the frame at timecode.
     *
     * @param  TimeCode $at
     * @return Frame
     */
    public function frame(TimeCode $at)
    {
        return new Frame($this, $this->driver, $this->ffprobe, $at);
    }
}
