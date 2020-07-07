<?php

namespace Illuminate\Queue;

class LuaScripts
{
    /**
     * Get the Lua script for computing the size of queue.
     *
     * KEYS[1] - The name of the primary queue
     * KEYS[2] - The name of the "delayed" queue
     * KEYS[3] - The name of the "reserved" queue
     *
     * @return string
     */
    public static function size()
    {
        return <<<'LUA'
return redis.call('llen', KEYS[1]) + redis.call('zcard', KEYS[2]) + redis.call('zcard', KEYS[3])
LUA;
    }

    /**
     * Get the Lua script for pushing jobs onto the queue.
     *
     * KEYS[1] - The queue to push the job onto, for example: queues:foo
     * KEYS[2] - The notification list fot the queue we are pushing jobs onto, for example: queues:foo:notify
     * ARGV[1] - The job payload
     *
     * @return string
     */
    public static function push()
    {
        return <<<'LUA'
-- Push the job onto the queue...
redis.call('rpush', KEYS[1], ARGV[1])
-- Push a notification onto the "notify" queue...
redis.call('rpush', KEYS[2], 1)
LUA;
    }

    /**
     * Get the Lua script for popping the next job off of the queue.
     *
     * KEYS[1] - The queue to pop jobs from, for example: queues:foo
     * KEYS[2] - The queue to place reserved jobs on, for example: queues:foo:reserved
     * KEYS[3] - The notify queue
     * ARGV[1] - The time at which the reserved job will expire
     *
     * @return string
     */
    public static function pop()
    {
        //从队列中取出第一个job
        //以retrieveNextJob 为例，这个例的KEYS[1] ，默认情况下为 default
        //如果取出任务，则将job内容decode
        //将 job 中的 attempts 加 1 ,表示尝试次数增加1
        //重新将 job 进行 encode
        //将 job 放到 reserved 队列中
        // zadd 中 KEYS[2],默认情况下为 default.reserved , ARG[1] 默认情况下为是一个时间戳
        //同时将 default.notify 队列中取出一个

        return <<<'LUA'
-- Pop the first job off of the queue...
local job = redis.call('lpop', KEYS[1])
local reserved = false

if(job ~= false) then
    -- Increment the attempt count and place job on the reserved queue...
    reserved = cjson.decode(job)
    reserved['attempts'] = reserved['attempts'] + 1
    reserved = cjson.encode(reserved)
    redis.call('zadd', KEYS[2], ARGV[1], reserved)
    redis.call('lpop', KEYS[3])
end

return {job, reserved}
LUA;
    }

    /**
     * Get the Lua script for releasing reserved jobs.
     *
     * KEYS[1] - The "delayed" queue we release jobs onto, for example: queues:foo:delayed
     * KEYS[2] - The queue the jobs are currently on, for example: queues:foo:reserved
     * ARGV[1] - The raw payload of the job to add to the "delayed" queue
     * ARGV[2] - The UNIX timestamp at which the job should become available
     *
     * @return string
     */
    public static function release()
    {
        //KEYS[2] 代表 default.reserved
        //ARGV[1] 代表 reserved job
        // KEYS[1] 代表 default.delayed
        //ARGV[2] 代表延迟时间
        return <<<'LUA'
-- Remove the job from the current queue...
redis.call('zrem', KEYS[2], ARGV[1])

-- Add the job onto the "delayed" queue...
redis.call('zadd', KEYS[1], ARGV[2], ARGV[1])

return true
LUA;
    }

    /**
     * Get the Lua script to migrate expired jobs back onto the queue.
     *
     * KEYS[1] - The queue we are removing jobs from, for example: queues:foo:reserved
     * KEYS[2] - The queue we are moving jobs to, for example: queues:foo
     * KEYS[3] - The notification list for the queue we are moving jobs to, for example queues:foo:notify
     * ARGV[1] - The current UNIX timestamp
     *
     * @return string
     */
    public static function migrateExpiredJobs()
    {
        return <<<'LUA'
-- Get all of the jobs with an expired "score"...
local val = redis.call('zrangebyscore', KEYS[1], '-inf', ARGV[1])

-- If we have values in the array, we will remove them from the first queue
-- and add them onto the destination queue in chunks of 100, which moves
-- all of the appropriate jobs onto the destination queue very safely.
if(next(val) ~= nil) then
    redis.call('zremrangebyrank', KEYS[1], 0, #val - 1)

    for i = 1, #val, 100 do
        redis.call('rpush', KEYS[2], unpack(val, i, math.min(i+99, #val)))
        -- Push a notification for every job that was migrated...
        for j = i, math.min(i+99, #val) do
            redis.call('rpush', KEYS[3], 1)
        end
    end
end

return val
LUA;
    }
}