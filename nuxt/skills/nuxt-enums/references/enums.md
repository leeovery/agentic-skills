# Enum Patterns

## Base Enum Class

All enums extend `Enum` from the base layer:

```typescript
import Enum from '#layers/base/app/enums/Enum'
```

### Core Methods

```typescript
// Create static enum value
static readonly Value = MyEnum.create('value')

// Coerce string to enum instance
static coerce(value: string): MyEnum

// Get all defined values
static values(): MyEnum[]

// Instance comparison
instance.is(MyEnum.Value)

// Get raw value
instance.value  // string
```

---

## Complete Enum Example

```typescript
// app/enums/PostStatus.ts
import Enum from '#layers/base/app/enums/Enum'
import type { Castable, EnumStoreObject } from '#layers/base/app/types'

export default class PostStatus extends Enum implements Castable {
  // Define all possible values
  static readonly Draft = PostStatus.create('draft')
  static readonly PendingReview = PostStatus.create('pending review')
  static readonly Published = PostStatus.create('published')
  static readonly Archived = PostStatus.create('archived')

  // Required for model casting
  static cast(value: string | EnumStoreObject): PostStatus {
    return PostStatus.coerce(value)
  }

  // UI color mapping
  color(): string {
    switch (this.value) {
      case 'draft':
        return 'neutral'
      case 'pending review':
        return 'warning'
      case 'published':
        return 'success'
      case 'archived':
        return 'error'
      default:
        return 'neutral'
    }
  }

  // Display text
  get text(): string {
    return this.value
  }

  // Custom display text (if different from value)
  get label(): string {
    switch (this.value) {
      case 'draft':
        return 'Draft'
      case 'pending review':
        return 'Pending Review'
      case 'published':
        return 'Published'
      case 'archived':
        return 'Archived'
      default:
        return this.value
    }
  }

  // Boolean helpers
  isEditable(): boolean {
    return this.is(PostStatus.Draft) || this.is(PostStatus.PendingReview)
  }

  isPublic(): boolean {
    return this.is(PostStatus.Published)
  }
}
```

---

## Castable Interface

Enums implement `Castable` for model integration:

```typescript
import type { Castable } from '#layers/base/app/types'

class PostStatus extends Enum implements Castable {
  // Static cast method - called by model hydration
  static cast(value: string | EnumStoreObject): PostStatus {
    return PostStatus.coerce(value)
  }
}
```

### In Models

```typescript
import PostStatus from '~/enums/PostStatus'

class Post extends Model {
  status: PostStatus

  public override casts(): Record<string, Castable> {
    return {
      status: PostStatus,  // Auto-casts string → PostStatus
    }
  }
}
```

---

## Behavior Methods

### Color Methods

Map enum values to UI colors:

```typescript
color(): string {
  switch (this.value) {
    case 'pending': return 'warning'
    case 'active': return 'success'
    case 'failed': return 'error'
    default: return 'neutral'
  }
}
```

Colors align with Nuxt UI color scheme:
- `primary`, `secondary`
- `success`, `warning`, `error`, `info`
- `neutral`

### Icon Methods

Map enum values to icons:

```typescript
icon(): string {
  switch (this.value) {
    case 'pending': return 'i-heroicons-clock'
    case 'active': return 'i-heroicons-check-circle'
    case 'failed': return 'i-heroicons-x-circle'
    default: return 'i-heroicons-question-mark-circle'
  }
}
```

### Boolean Helpers

Add semantic checks:

```typescript
isPending(): boolean {
  return this.is(TaskStatus.Pending)
}

isCompleted(): boolean {
  return this.is(TaskStatus.Completed)
}

canBeEdited(): boolean {
  return this.is(TaskStatus.Pending) || this.is(TaskStatus.InProgress)
}
```

---

## Template Usage

### Badge Display

```vue
<UBadge :color="post.status.color()">
  {{ post.status.text }}
</UBadge>
```

### With Icon

```vue
<UBadge :color="post.status.color()">
  <UIcon :name="post.status.icon()" class="mr-1" />
  {{ post.status.label }}
</UBadge>
```

### Conditional Rendering

```vue
<template>
  <div v-if="post.status.isEditable()">
    <!-- Show editable UI -->
  </div>

  <UButton
    v-if="post.status.isEditable()"
    @click="publish"
  >
    Publish
  </UButton>
</template>
```

### Select Options

```vue
<USelect
  v-model="filters.status"
  :options="statusOptions"
  placeholder="Filter by status"
/>

<script setup>
import PostStatus from '~/enums/PostStatus'

const statusOptions = computed(() =>
  PostStatus.values().map(status => ({
    value: status.value,
    label: status.label,
  }))
)
</script>
```

---

## Comparison Methods

### Single Comparison

```typescript
// Check if enum matches specific value
if (post.status.is(PostStatus.Published)) {
  // Handle published post
}
```

### Multiple Comparison

```typescript
// Check if enum is one of several values
const editableStatuses = [PostStatus.Draft, PostStatus.PendingReview]
if (editableStatuses.some(s => post.status.is(s))) {
  // Handle editable post
}

// Or add helper method
isEditable(): boolean {
  return [PostStatus.Draft, PostStatus.PendingReview].some(s => this.is(s))
}
```

---

## Static Methods

### Get All Values

```typescript
// Returns array of all defined enum instances
const allStatuses = PostStatus.values()
// [Draft, PendingReview, Published, Archived]
```

### Find by Value

```typescript
// Coerce string back to enum instance
const status = PostStatus.coerce('published')
status.is(PostStatus.Published)  // true
```

---

## More Enum Examples

### TaskStatus

```typescript
// app/enums/TaskStatus.ts
export default class TaskStatus extends Enum implements Castable {
  static readonly Pending = TaskStatus.create('pending')
  static readonly Scheduled = TaskStatus.create('scheduled')
  static readonly InProgress = TaskStatus.create('in_progress')
  static readonly Completed = TaskStatus.create('completed')
  static readonly Failed = TaskStatus.create('failed')

  static cast(value: string): TaskStatus {
    return TaskStatus.coerce(value)
  }

  color(): string {
    switch (this.value) {
      case 'pending': return 'warning'
      case 'scheduled': return 'info'
      case 'in_progress': return 'primary'
      case 'completed': return 'success'
      case 'failed': return 'error'
      default: return 'neutral'
    }
  }
}
```

### NotificationStatus

```typescript
// app/enums/NotificationStatus.ts
export default class NotificationStatus extends Enum implements Castable {
  static readonly Pending = NotificationStatus.create('pending')
  static readonly Sent = NotificationStatus.create('sent')
  static readonly Delivered = NotificationStatus.create('delivered')
  static readonly Failed = NotificationStatus.create('failed')

  static cast(value: string): NotificationStatus {
    return NotificationStatus.coerce(value)
  }

  color(): string {
    switch (this.value) {
      case 'pending': return 'warning'
      case 'sent': return 'info'
      case 'delivered': return 'success'
      case 'failed': return 'error'
      default: return 'neutral'
    }
  }

  wasSent(): boolean {
    return !this.is(NotificationStatus.Pending)
  }
}
```

---

## Directory Structure

```
app/
└── enums/
    ├── PostStatus.ts
    ├── TaskStatus.ts
    ├── CommentStatus.ts
    └── NotificationStatus.ts
```

---

## Naming Conventions

| Convention | Example |
|------------|---------|
| File name | PascalCase: `PostStatus.ts` |
| Class name | PascalCase: `PostStatus` |
| Static values | PascalCase: `Draft`, `InProgress` |
| String values | lowercase/snake: `'draft'`, `'in_progress'` |

---

## Related Skills

- **[nuxt-models](../../nuxt-models/SKILL.md)** - Using enums in models
- **[nuxt-components](../../nuxt-components/SKILL.md)** - Enum display patterns
