# Real-time Patterns

## Configuration

### Environment Variables

```bash
# .env
NUXT_PUBLIC_ECHO_KEY=your-pusher-key
NUXT_PUBLIC_ECHO_HOST=echo.example.com
NUXT_PUBLIC_ECHO_SCHEME=https
NUXT_PUBLIC_ECHO_PORT=443
```

### Nuxt Config

```typescript
// nuxt.config.ts
export default defineNuxtConfig({
  modules: ['nuxt-laravel-echo'],

  runtimeConfig: {
    public: {
      echo: {
        key: undefined,
        host: undefined,
        scheme: undefined,
        port: undefined,
      },
    },
  },
})
```

---

## Constants

### Channel Names

```typescript
// app/constants/channels.ts

// Collection channels
export const Posts = 'posts'
export const Authors = 'authors'
export const Comments = 'comments'

// Resource channels (with parameter)
export const Post = 'post.{post}'
export const Author = 'author.{author}'
export const Conversation = 'conversation.{conversation}'

// User-specific channels
export const UserNotifications = 'user.{user}.notifications'
```

### Event Names

```typescript
// app/constants/events.ts

// Post events
export const PostCreated = 'PostCreated'
export const PostUpdated = 'PostUpdated'
export const PostDeleted = 'PostDeleted'

// Comment events
export const CommentCreated = 'CommentCreated'
export const CommentApproved = 'CommentApproved'
export const CommentRejected = 'CommentRejected'

// Conversation events
export const MessageReceived = 'MessageReceived'
export const ConversationEnded = 'ConversationEnded'

// System events
export const NotificationReceived = 'NotificationReceived'
```

---

## useRealtime Composable

```typescript
const {
  privateChannel,    // Private channel subscription
  presenceChannel,   // Presence channel (with user info)
  leaveChannel,      // Unsubscribe from channel
} = useRealtime()
```

---

## Private Channel Pattern

### Basic Subscription

```typescript
import { Posts, PostCreated, PostUpdated } from '~/constants/channels'
import { PostCreated, PostUpdated } from '~/constants/events'

const { privateChannel } = useRealtime()

// Subscribe to private channel
const channel = privateChannel(Posts)

// Listen for single event
channel.on(PostCreated, (event) => {
  console.log('New post:', event.post)
  refresh()
})

// Listen for multiple events
channel.on([PostCreated, PostUpdated], (event) => {
  refresh()
})
```

### Channel with Parameter

```typescript
import { Post, PostUpdated, PostDeleted } from '~/constants'

// Channel with dynamic ID
const channel = privateChannel(Post, post.ulid)
// Subscribes to: post.{ulid}

channel.on([PostUpdated, PostDeleted], refresh)
```

### Cleanup on Unmount

```typescript
const { privateChannel, leaveChannel } = useRealtime()

// Subscribe
const channel = privateChannel(Post, post.ulid)
channel.on(PostUpdated, refresh)

// Cleanup
onUnmounted(() => {
  leaveChannel(Post, post.ulid)
})
```

---

## Complete Page Example

```vue
<!-- app/pages/posts/[ulid].vue -->
<script lang="ts" setup>
import { Post, PostUpdated, PostDeleted } from '~/constants/channels'
import { PostUpdated, PostDeleted } from '~/constants/events'

const route = useRoute()
const router = useRouter()
const ulid = computed(() => route.params.ulid as string)

// Query
const getPostQuery = getPostQueryFactory()
const { data: post, refresh } = getPostQuery(ulid)

// Real-time updates
const { privateChannel, leaveChannel } = useRealtime()

// Subscribe to post channel
const channel = privateChannel(Post, ulid.value)

// Handle updates
channel.on(PostUpdated, () => {
  refresh()
})

// Handle deletion
channel.on(PostDeleted, () => {
  flash.info('This post has been deleted')
  router.push('/posts')
})

// Cleanup on unmount
onUnmounted(() => {
  leaveChannel(Post, ulid.value)
})
</script>
```

---

## List Page with Real-time

```vue
<!-- app/pages/posts/index.vue -->
<script lang="ts" setup>
import { Posts, PostCreated, PostUpdated, PostDeleted } from '~/constants'

// Query
const getPostsQuery = getPostsQueryFactory()
const { data: posts, refresh } = getPostsQuery(filters)

// Real-time updates for list
const { privateChannel } = useRealtime()

privateChannel(Posts).on(
  [PostCreated, PostUpdated, PostDeleted],
  refresh
)
</script>
```

---

## Composable with Real-time

```typescript
// app/composables/useRemainingTaskCount.ts
import { Tasks, TaskCreated, TaskCompleted } from '~/constants'

export default function useRemainingTaskCount() {
  const remainingTaskCount = useState<number>('remaining-task-count', () => 0)
  const taskApi = useRepository('tasks')
  const { privateChannel } = useRealtime()

  const fetchTaskCount = async () => {
    const { data } = await taskApi.list({ filter: { status: 'pending' } })
    remainingTaskCount.value = data.length
  }

  const init = () => {
    // Initial fetch
    fetchTaskCount()

    // Real-time updates
    privateChannel(Tasks).on(
      [TaskCreated, TaskCompleted],
      fetchTaskCount
    )
  }

  return { remainingTaskCount, init }
}
```

---

## Presence Channel Pattern

For channels that track online users:

```typescript
const { presenceChannel } = useRealtime()

// Join presence channel
const channel = presenceChannel('room.{room}', roomId)

// Handle events
channel.on('here', (users) => {
  // Initial list of users in channel
  onlineUsers.value = users
})

channel.on('joining', (user) => {
  // User joined
  onlineUsers.value.push(user)
})

channel.on('leaving', (user) => {
  // User left
  onlineUsers.value = onlineUsers.value.filter(u => u.id !== user.id)
})

// Listen for custom events too
channel.on('MessageSent', (event) => {
  messages.value.push(event.message)
})
```

---

## Error Handling

```typescript
const { privateChannel } = useRealtime()

const channel = privateChannel(Posts)

channel.error((error) => {
  console.error('Channel error:', error)
  flash.error('Real-time connection failed')
})
```

---

## Best Practices

### 1. Always Clean Up

```typescript
// Store channel reference for cleanup
const channel = privateChannel(Post, id)
channel.on(PostUpdated, refresh)

onUnmounted(() => {
  leaveChannel(Post, id)
})
```

### 2. Use Constants

```typescript
// DON'T: Magic strings
privateChannel('posts').on('PostCreated', ...)

// DO: Use constants
privateChannel(Posts).on(PostCreated, ...)
```

### 3. Debounce Refreshes

```typescript
import { useDebounceFn } from '@vueuse/core'

const debouncedRefresh = useDebounceFn(refresh, 300)

channel.on([PostCreated, PostUpdated], debouncedRefresh)
```

### 4. Check Connection

```typescript
const { isConnected } = useRealtime()

// Show connection status
<UBadge v-if="!isConnected" color="warning">Reconnecting...</UBadge>
```

---

## Directory Structure

```
app/
└── constants/
    ├── channels.ts    # Channel name constants
    └── events.ts      # Event name constants
```

---

## Related Skills

- **[nuxt-config](../../nuxt-config/SKILL.md)** - Echo configuration
- **[nuxt-composables](../../nuxt-composables/SKILL.md)** - useRealtime
- **[nuxt-features](../../nuxt-features/SKILL.md)** - Real-time in queries
