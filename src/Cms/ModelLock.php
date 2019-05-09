<?php

namespace Kirby\Cms;

use Kirby\Data\Data;
use Kirby\Toolkit\F;

use Throwable;
use Kirby\Exception\PermissionException;

/**
 * Takes care of content lock and unlock information
 *
 * @package   Kirby Cms
 * @author    Nico Hoffmann <nico@getkirby.com>
 * @link      https://getkirby.com
 * @copyright Bastian Allgeier GmbH
 * @license   https://getkirby.com/license
 */
class ModelLock
{

    /**
     * Lock data
     *
     * @var array
     */
    protected $data;

    /**
     * The model
     *
     * @var ModelWithContent
     */
    protected $model;

    /**
     * @param ModelWithContent $model
     */
    public function __construct($model)
    {
        $this->model = $model;
        $this->data  = $this->read();
    }

    /**
     * Sets lock with the current user
     *
     * @return bool
     */
    public function create(): bool
    {
        $id = $this->id();

        // Check if model is already locked by another user
        if (
            $this->has('lock') &&
            $this->data[$id]['lock']['user'] !== $this->user()->id()
        ) {
            throw new PermissionException($id . ' is already locked');
        }

        $this->data[$id]['lock'] = [
            'user' => $this->user()->id(),
            'time' => time()
        ];

        return $this->write();
    }

    /**
     * Returns path to lock file
     *
     * @return string
     */
    protected function file(): string
    {
        return $this->model()->contentFileDirectory() . '/.lock';
    }

    /**
     * Returns array  with `locked` flag and,
     * if needed, `user`, `email`, `time`, `canUnlock`
     *
     * @return array
     */
    public function get(): array
    {
        $data = $this->data[$this->id()] ?? [];
        $data = $data['lock'] ?? [];

        if (
            empty($data) === false &&
            $data['user'] !== $this->user()->id() &&
            $user = $this->kirby()->user($data['user'])
        ) {
            return [
                'locked'    => true,
                'user'      => $user->id(),
                'email'     => $user->email(),
                'time'      => $time = intval($data['time']),
                'canUnlock' => $time + $this->kirby()->option('lock.duration', 60 * 2) <= time()
            ];
        }

        return [
            'locked' => false
        ];
    }

    /**
     * Checks if key in data array exists
     *
     * @return bool
     */
    protected function has(string $type): bool
    {
        return isset($this->data[$this->id()]) === true &&
        isset($this->data[$this->id()][$type]) === true;
    }

    /**
     * Returns prepended model id
     *
     * @return string
     */
    protected function id(): string
    {
        return '/' . $this->model()->id();
    }

    /**
     * Returns if the model is locked by another user
     *
     * @return bool
     */
    public function isLocked(): bool
    {
        $lock = $this->get();

        if (
            $lock['locked'] === true &&
            $lock['user'] !== $this->user()->id()
        ) {
            return true;
        }

        return false;
    }

    /**
     * Returns if the user's lock has been removed by another user
     *
     * @return bool
     */
    public function isUnlocked(): bool
    {
        $data = $this->data[$this->id()] ?? [];
        $data = $data['unlock'] ?? [];

        return in_array($this->user()->id(), $data) === true;
    }

    /**
     * Returns the app instance
     *
     * @return Kirby\Cms\App
     */
    protected function kirby(): App
    {
        return $this->model()->kirby();
    }

    /**
     * Returns the model object
     *
     * @return Kirby\Cms\ModelWithContent
     */
    protected function model(): ModelWithContent
    {
        return $this->model;
    }

    /**
     * Helper method to read out the lock file
     *
     * @return array
     */
    protected function read(): array
    {
        try {
            return Data::read($this->file(), 'yaml');
        } catch (Throwable $th) {
            return [];
        }
    }

    /**
     * Removes lock of current user
     *
     * @return bool
     */
    public function remove(): bool
    {
        // if no lock exists, skip
        if ($this->has('lock') === false) {
            return true;
        }

        $id   = $this->id();
        $data = $this->data[$id]['lock'];

        // check if lock was set by another user
        if ($data['user'] !== $this->user()->id()) {
            throw new PermissionException('The content lock can only be removed by the user who created it. Use unlock instead.');
        }

        // remove lock
        unset($this->data[$id]['lock']);

        return $this->write();
    }

    /**
     * Removes unlock information for current user
     *
     * @return bool
     */
    public function resolve(): bool
    {
        // if no unlocks exist, skip
        if ($this->has('unlock') === false) {
            return true;
        }

        $id = $this->id();

        // remove user from unlock array
        $this->data[$id]['unlock'] = array_diff(
            $this->data[$id]['unlock'],
            [$this->user()->id()]
        );

        return $this->write();
    }

    /**
     * Removes current lock and adds lock user to unlock data
     *
     * @return bool
     */
    public function unlock(): bool
    {
        // if no lock exists, skip
        if ($this->has('lock') === false) {
            return true;
        }

        // store lock data
        $id   = $this->id();
        $data = $this->data[$id]['lock'];

        // add lock user to unlocked data
        $this->data[$id]['unlock']   = $this->data[$id]['unlock'] ?? [];
        $this->data[$id]['unlock'][] = $data['user'];

        // remove lock
        unset($this->data[$id]['lock']);

        return $this->write();
    }

    /**
     * Get current authenticated user.
     * Throws exception if none is authenticated.
     *
     * @return Kirby\Cms\User
     */
    protected function user(): User
    {
        if ($user = $this->kirby()->user()) {
            return $user;
        }

        throw new PermissionException('No user authenticated.');
    }

    /**
     * Helper method to write to the lock file
     *
     * @return bool
     */
    protected function write(): bool
    {
        // make sure to unset model id entries,
        // if no lock data for the model exists
        foreach ($this->data as $id => $data) {
            if (
                isset($data['lock']) === false &&
                (isset($data['unlock']) === false ||
                count($data['unlock']) === 0)
            ) {
                unset($this->data[$id]);
            } elseif (
                isset($data['unlock']) === true &&
                count($data['unlock']) === 0
            ) {
                unset($this->data[$id]['unlock']);
            }
        }

        // if no data is left, remove file
        if (count($this->data) === 0) {
            return F::remove($this->file());
        }

        return Data::write($this->file(), $this->data, 'yaml');
    }
}
