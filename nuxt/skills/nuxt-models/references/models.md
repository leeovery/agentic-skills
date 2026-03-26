# Model Patterns

## Base Model Class

All models extend `Model` from the base layer:

```typescript
import Model from '#layers/base/app/models/Model'
```

### Static Factory Methods

```typescript
// Hydrate single instance from data
const post = Post.hydrate(apiData)

// Hydrate collection from array
const posts = Post.collect(apiDataArray)

// Create instance (alias for hydrate)
const post = Post.instance(data)
```

### Instance Methods

```typescript
// Compare by primary key
post.is(otherPost)  // true if same primary key

// Clone instance
const copy = post.clone()
```

### Override Methods

```typescript
class Post extends Model {
  // Custom primary key (default: 'id')
  public override primaryKey(): string {
    return 'ulid'
  }

  // Transform raw data before assignment
  public override transform<D>(data: D): D {
    return transformKeys(data, camelCase)
  }

  // Define property casts
  public override casts(): Record<string, Castable> {
    return { status: PostStatus, createdAt: DateValue }
  }

  // Define model relations
  public override relations(): Record<string, typeof Model> {
    return { author: Author, comments: Comment }
  }

  // Strict mode (default: true) - only assign declared properties
  public override strictProperties(): boolean {
    return true
  }
}
```

### Lifecycle Hooks

```typescript
class Post extends Model {
  // Before hydration
  public override booting(): void {
    // Called before property assignment
  }

  // After hydration complete
  public override booted(): void {
    // Called after casts and relations applied
    // Good for computed setup, event registration
  }
}
```

---

## Model Lifecycle Flow

```
API Response Data
       ↓
booting() hook
       ↓
transform() - Transform raw data (e.g., snake_case → camelCase)
       ↓
Property Assignment (strict or loose based on strictProperties())
       ↓
applyRelations() - Hydrate nested models from relations()
       ↓
applyCasts() - Cast properties using casts()
       ↓
booted() hook
       ↓
Model Instance Ready
```

---

## Complete Model Example

```typescript
// app/models/Post.ts
import Model from '#layers/base/app/models/Model'
import type { Castable } from '#layers/base/app/types'
import PostStatus from '~/enums/PostStatus'
import DateValue from '~/values/DateValue'
import Author from '~/models/Author'
import Comment from '~/models/Comment'
import Tag from '~/models/Tag'

export default class Post extends Model {
  // Scalar properties
  ulid: string
  title: string
  content: string
  isDraft: boolean

  // Cast properties (from enums/values)
  status: PostStatus
  publishedAt: DateValue
  createdAt: DateValue
  updatedAt: DateValue

  // Relation properties
  author: Author
  comments?: Comment[]
  commentsCount?: number
  tags?: Tag[]
  tagsCount?: number

  // Custom primary key
  public override primaryKey(): string {
    return 'ulid'
  }

  // Define casts
  public override casts(): Record<string, Castable> {
    return {
      status: PostStatus,
      publishedAt: DateValue,
      createdAt: DateValue,
      updatedAt: DateValue,
    }
  }

  // Define relations
  public override relations(): Record<string, typeof Model> {
    return {
      author: Author,
      comments: Comment,
      tags: Tag,
    }
  }

  // Instance methods
  public isPublished(): boolean {
    return !this.isDraft
  }

  public hasComments(): boolean {
    return (this.commentsCount ?? 0) > 0
  }

  public hasTags(): boolean {
    return (this.tagsCount ?? 0) > 0
  }
}
```

---

## Relations

### One-to-One

```typescript
// Author is a single related model
author: Author

relations(): Record<string, typeof Model> {
  return {
    author: Author,  // Hydrates as Author instance
  }
}
```

### One-to-Many

```typescript
// Comments is an array of related models
comments?: Comment[]

relations(): Record<string, typeof Model> {
  return {
    comments: Comment,  // Hydrates each item as Comment instance
  }
}
```

### Counts

API often returns counts alongside relations:

```typescript
// From API: { comments: [...], comments_count: 5 }
comments?: Comment[]
commentsCount?: number
```

The count is a scalar - no relation definition needed.

---

## Casts

### Castable Interface

Any class implementing `Castable` can be used in casts:

```typescript
interface Castable {
  // Static cast method
}

// Implementation
class PostStatus extends Enum implements Castable {
  static cast(value: string): PostStatus {
    return PostStatus.coerce(value)
  }
}
```

### Built-in Castable Types

```typescript
// Enums - extend Enum base class
status: PostStatus

// Value objects - implement Castable
createdAt: DateValue
publishedAt: DateValue
```

### Nullable Casts

```typescript
// If property might be null, make it optional
publishedAt?: DateValue

// The cast still works - undefined/null values skip casting
casts(): Record<string, Castable> {
  return {
    publishedAt: DateValue,  // Only casts if value exists
  }
}
```

---

## Data Transformation

### Snake Case to Camel Case

```typescript
import { transformKeys } from '#layers/base/app/utils'
import { camelCase } from 'change-case'

export default class User extends Model {
  uuid: string
  sessionId: string      // From API: session_id
  organizationId: string // From API: organization_id

  public override transform<D>(data: D): D {
    return transformKeys(data, camelCase)
  }
}
```

---

## Model with Booted Hook

```typescript
import Model from '#layers/base/app/models/Model'
import { registerPermissions } from '~/composables/usePermissions'

export default class User extends Model {
  uuid: string
  name: string
  email: string
  permissions?: string[]

  public override booted() {
    // Register permissions after hydration
    if (this.permissions) {
      registerPermissions(this.permissions)
    }
  }
}
```

---

## Simple Model (No Relations)

```typescript
// app/models/Tag.ts
import Model from '#layers/base/app/models/Model'
import type { Castable } from '#layers/base/app/types'
import DateValue from '~/values/DateValue'

export default class Tag extends Model {
  ulid: string
  name: string
  slug: string
  createdAt: DateValue

  public override primaryKey(): string {
    return 'ulid'
  }

  public override casts(): Record<string, Castable> {
    return {
      createdAt: DateValue,
    }
  }
}
```

---

## Usage Patterns

### Hydrating from Repository

```typescript
// Repositories with hydration enabled return model instances
const postApi = useRepository('posts')
const { data: post } = await postApi.get('ulid123')

// post is already a Post instance
post.status.color()
post.author.name
```

### Manual Hydration

```typescript
// When you have raw data
const rawData = { ulid: '...', status: 'published', ... }
const post = Post.hydrate(rawData)
```

### Collection Hydration

```typescript
// Hydrate array of items
const rawArray = [{ ... }, { ... }]
const posts = Post.collect(rawArray)

posts.forEach(post => {
  console.log(post.status.color())
})
```

### Model Comparison

```typescript
// Compare by primary key
if (selectedPost.is(post)) {
  // Same post
}

// Find in array
const found = posts.find(p => p.is(targetPost))
```

---

## Naming Conventions

| Convention | Example |
|------------|---------|
| File name | PascalCase: `Post.ts`, `Author.ts` |
| Class name | PascalCase: `Post`, `Author` |
| Properties | camelCase: `createdAt`, `commentsCount` |
| Relations | camelCase, matches property: `author`, `comments` |

---

## Related Skills

- **[nuxt-enums](../../nuxt-enums/SKILL.md)** - Enum casting
- **[nuxt-repositories](../../nuxt-repositories/SKILL.md)** - Repository hydration
- **[nuxt-features](../../nuxt-features/SKILL.md)** - Using models in features
