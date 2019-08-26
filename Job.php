<?php
/**
 * OriginPHP Framework
 * Copyright 2018 - 2019 Jamiel Sharief.
 *
 * Licensed under The MIT License
 * The above copyright notice and this permission notice shall be included in all copies or substantial
 * portions of the Software.
 *
 * @copyright   Copyright (c) Jamiel Sharief
 * @link        https://www.originphp.com
 * @license     https://opensource.org/licenses/mit-license.php MIT License
 */

namespace Origin\Job;

use \ArrayObject;
use Origin\Model\Model;
use Origin\Exception\Exception;
use Origin\Model\ModelRegistry;
use Origin\Job\Engine\BaseEngine;
use Origin\Model\Exception\MissingModelException;

/**
 * (new SendUserWelcomeEmail($user))->dispatch();
 * (new SendUserWelcomeEmail($user))->dispatch(['wait' => '+5 minutes']);
 */

class Job
{
    /**
     * This is the display name for the job
     *
     * @example SendWelcomeEmail
     *
     * @var string
     */
    public $name = null;

    /**
     * The name of the queue for this job
     *
     * @var string
     */
    public $queue = 'default';

    /**
     * The name of the queue connection to use
     *
     * @var string
     */
    public $connection = 'default';

    /**
     * Default wait time before dispatching the job, this is a strtotime compatible
     * string. e.g '+5 minutes' or '+1 day' etc
     *
     * @example '+30 minutes'
     * @var string
     */
    public $wait = null;

    /**
     * The default timeout in second
     *
     * @var integer
     */
    public $timeout = 60;

    /**
     * Job identifier
     *
     * @var mixed
     */
    protected $id = null;
    
    /**
     * Adapter id
     *
     * @var mixed
     */
    protected $backendId = null;

    /**
     * These are the arguments that will be passed on to execute
     *
     * @var array
     */
    protected $arguments = [];

    /**
     * Number of times this has been executed
     *
     * @var integer
     */
    protected $attempts = 0;

    /**
     * The date this job was enqueued.
     *
     * @var string
     */
    protected $enqueued = null;

    /**
     * If retry is called the info is stored here.
     *
     * @var array
     */
    protected $retryOptions = null;

    /**
     * Constructor
     */
    public function __construct()
    {
        $this->arguments = func_get_args();
        
        $this->id = uuid();

        if ($this->name === null) {
            list($namespace, $name) = namespaceSplit(get_class($this));
  
            $this->name = substr($name, 0, -3);
        }

        if (! method_exists($this, 'execute')) {
            throw new Exception('Job must have an execute method');
        }
    }

    /**
     * This is the hook when the job is created for sending
     *
     * @return void
     */
    public function initialize()
    {
    }

    /**
     * This is called just before execute
     *
     * @return void
     */
    public function startup()
    {
    }

    ## execute method is not defined so user can add type hints etc

    /**
     * This is called after execute
     *
     * @return void
     */
    public function shutdown()
    {
    }

    /**
     * This callback is triggered when an error occurs
     *
     * @param \Exception $exception
     * @return void
     */
    public function onError(\Exception $exception)
    {
    }

    /**
     * Gets the id for this job
     *
     * @param string|int
     * @retun string
     */
    public function id() : string
    {
        return $this->id;
    }

    /**
     * Sets/gets the id by backend if any
     *
     * @param int|string $id
     * @return int|string|void
     */
    public function backendId($id = null)
    {
        if ($id === null) {
            return $this->backendId;
        }
        $this->backendId = $id;
    }

    /**
    * Returns the connection for the Queue
    *
    * @return void
    */
    public function connection() : BaseEngine
    {
        $connection = env('ORIGIN_ENV') === 'test' ?'test':$this->connection;

        return Queue::connection($connection);
    }

    /**
     * Dispatches the job to the queue
     *
     * @param array $options These options will overide the set properties. The available option keys are:
     *   - wait: a strtotime comptabile string defaults to 5 seconds. e.g. '+ 5 minutes'
     *   - queue: the queue to dispatch if different to configured
     * @return void
     */
    public function dispatch(array $options = []) : void
    {
        $options += [
            'wait' => $this->wait ?: 'now',
            'queue' => $this->queue,
        ];
        $this->wait = $options['wait'];
        $this->queue = $options['queue'];
        $this->enqueued = date('Y-m-d H:i:s');

        $this->connection()->add($this, $options['wait']);
    }

    /**
    * Retries a job
    *
    * @param array $options The following option keys are supported :
    *   - wait: a strtotime comptabile string defaults to 5 seconds. e.g. '+ 5 minutes'
    *   - limit: The maximum number of retries to do. Default:3
    * @return bool
    */
    public function retry(array $options = []) : bool
    {
        $options += ['wait' => '+ 5 seconds','limit' => 3];

        if ($this->attempts() < $options['limit'] + 1) {
            $this->retryOptions = $options;

            return true;
        }

        return false;
    }

    /**
     * Gets the number of attempts
     *
     * @return int
     */
    public function attempts() : int
    {
        return $this->attempts;
    }

    /**
     * Dispatches the job immediately.
     * @internal if id set that means job has been serialized/unserialzied
     *
     * @return bool
     */
    public function dispatchNow() : bool
    {
        $this->attempts ++;
        
        try {
            $this->initialize();
            $this->startup();
            $this->execute(...$this->arguments);
        } catch (\Throwable $e) {
            $this->shutdown();
        
            if ($this->enqueued) {
                $this->connection()->fail($this);
            }
           
            $this->onError($e);
    
            if ($this->enqueued and $this->retryOptions) {
                $this->connection()->retry($this, $this->retryOptions['limit'], $this->retryOptions['wait']);
            }

            return false;
        }

        $this->shutdown();

        if ($this->enqueued) {
            $this->connection()->success($this);
        }
       
        if (method_exists($this, 'onSuccess')) {
            $this->onSuccess(...$this->arguments);
        }

        return true;
    }

    /**
     * Loads a model
     *
     * @param string $model
     * @param array $options
     * @return \Origin\Model\Model
     */
    public function loadModel(string $model, array $options = []) : Model
    {
        list($plugin, $alias) = pluginSplit($model);

        if (isset($this->{$alias})) {
            return $this->{$alias};
        }

        $this->{$alias} = ModelRegistry::get($model, $options);

        if ($this->{$alias}) {
            return $this->{$alias};
        }
        throw new MissingModelException($model);
    }

    /**
     * Gets an array of the arguments that will be called with execute
     *
     * @return array
     */
    public function arguments() : array
    {
        return $this->arguments;
    }

    /**
     * Returns an array of data to be passed to connection
     * to be serialized
     */
    public function serialize() : array
    {
        return [
            'className' => get_class($this),
            'id' => $this->id,
            'backendId' => $this->backendId,
            'queue' => $this->queue,
            'arguments' => serialize(new ArrayObject($this->arguments)),
            'attempts' => $this->attempts,
            'enqueued' => $this->enqueued,
            'serialized' => date('Y-m-d H:i:s'),
        ];
    }

    /**
     * Deserializes data from Job::serialize
     *
     * @param array $data
     * @return void
     */
    public function deserialize(array $data) : void
    {
        $this->id = $data['id'];
        $this->backendId = $data['backendId'];
        $this->queue = $data['queue'];
        $this->arguments = (array) unserialize($data['arguments']); # unserialize object and convert to []
        $this->attempts = $data['attempts'];
        $this->enqueued = $data['enqueued'];
        $this->serialized = $data['serialized'];
    }
}
