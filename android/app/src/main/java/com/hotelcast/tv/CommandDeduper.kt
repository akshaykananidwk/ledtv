package com.hotelcast.tv

/**
 * Remembers the most recent [capacity] command ids so that at-least-once delivery from the
 * server never executes a command twice. Pure Kotlin (unit-testable); persisted as a CSV string.
 */
class CommandDeduper(private val capacity: Int = 200) {
    private val ids = LinkedHashSet<Long>()

    @Synchronized
    fun isHandled(id: Long): Boolean = ids.contains(id)

    /** Marks [id] as handled. Returns true if it was new (i.e. the command should be executed). */
    @Synchronized
    fun markHandled(id: Long): Boolean {
        if (ids.contains(id)) return false
        ids.add(id)
        while (ids.size > capacity) {
            val first = ids.iterator()
            first.next()
            first.remove()
        }
        return true
    }

    @Synchronized
    fun size(): Int = ids.size

    @Synchronized
    fun clear() = ids.clear()

    @Synchronized
    fun serialize(): String = ids.joinToString(",")

    companion object {
        fun deserialize(value: String?, capacity: Int = 200): CommandDeduper {
            val d = CommandDeduper(capacity)
            value?.split(',')?.mapNotNull { it.trim().toLongOrNull() }?.forEach { d.markHandled(it) }
            return d
        }
    }
}
