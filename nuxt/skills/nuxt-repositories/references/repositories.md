# Repository Patterns

## BaseRepository Class

All repositories extend `BaseRepository<T>` from the base layer:

```typescript
import { BaseRepository } from '#layers/base/app/repositories/base-repository'
```

### Core Properties

```typescript
class MyRepository extends BaseRepository<MyModel> {
  // API resource path (required)
  protected resource = '/api/my-resource'

  // Enable model hydration (optional, default: false)
  protected hydration = true

  // Hydrator instance (required if hydration: true)
  protected hydrator = new ModelHydrator(MyModel)
}
```

### Inherited CRUD Methods

```typescript
// List all (with optional params)
list(params?: GenericQueryParams): Promise<CollectionResponse<T> | PaginatedResponse<T>>

// Get single by ID
get(id: string | number, params?): Promise<DataResponse<T>>

// Create new
create(data?): Promise<DataResponse<T>>

// Update existing
update(id: string | number, data): Promise<DataResponse<T>>

// Delete
delete(id: string | number): Promise<void>
```

### HTTP Methods

```typescript
// Available for custom methods
jsonGet(url: string, params?): Promise<DataResponse<T>>
jsonPost(url: string, data?): Promise<DataResponse<T>>
jsonPut(url: string, data?): Promise<DataResponse<T>>
jsonPatch(url: string, data?): Promise<DataResponse<T>>
jsonDelete(url: string): Promise<void>
```

---

## Complete Repository Example

```typescript
// app/repositories/PostRepository.ts
import { BaseRepository } from '#layers/base/app/repositories/base-repository'
import { ModelHydrator } from '#layers/base/app/repositories/hydrators/model-hydrator'
import Post from '~/models/Post'
import type { CollectionResponse, DataResponse, GenericQueryParams, OutgoingQueryParams } from '#layers/base/app/types'

export default class PostRepository extends BaseRepository<Post> {
  // API resource path
  protected resource = '/api/posts'

  // Enable automatic model hydration
  protected hydration = true
  protected hydrator = new ModelHydrator(Post)

  // Custom: List posts by author
  async listByAuthor(
    authorUlid: string,
    params?: GenericQueryParams & OutgoingQueryParams
  ): Promise<CollectionResponse<Post>> {
    return this.jsonGet(`/api/authors/${authorUlid}/posts`, params)
  }

  // Custom: Update post status
  async updateStatus(
    ulid: string,
    status: string
  ): Promise<DataResponse<Post>> {
    return this.jsonPatch(`${this.resource}/${ulid}/status`, { status })
  }
}
```

---

## Model Hydration

### Enabling Hydration

```typescript
import { ModelHydrator } from '#layers/base/app/repositories/hydrators/model-hydrator'
import Post from '~/models/Post'

class PostRepository extends BaseRepository<Post> {
  protected hydration = true
  protected hydrator = new ModelHydrator(Post)
}
```

### How It Works

1. Repository makes API call
2. Response passes through hydrator
3. Single items: `Model.hydrate(data)`
4. Collections: `Model.collect(data)`
5. Typed model instances returned

### Hydration Control

```typescript
const postApi = useRepository('posts')

// Temporarily disable hydration
const rawData = await postApi.withoutHydration(async (repo) => {
  return repo.list()  // Returns raw API response
})

// Ensure hydration (even if normally disabled)
const models = await postApi.withHydration(async (repo) => {
  return repo.list()  // Returns hydrated models
})
```

---

## Repository Registration

### Simple Registration

```typescript
// app/app.config.ts
import PostRepository from '~/repositories/PostRepository'
import AuthorRepository from '~/repositories/AuthorRepository'
import CommentRepository from '~/repositories/CommentRepository'

export default defineAppConfig({
  repositories: {
    posts: PostRepository,
    authors: AuthorRepository,
    comments: CommentRepository,
  },
})
```

### With Custom Fetch Options

```typescript
export default defineAppConfig({
  repositories: {
    // Simple format (uses global apiUrl)
    posts: PostRepository,

    // Extended format with custom options
    externalService: {
      repository: ExternalRepository,
      fetchOptions: {
        baseURL: 'https://external-api.example.com',
        timeout: 30000,
        headers: {
          'X-Service': 'external',
        },
      },
    },
  },
})
```

### Environment Variable Overrides

```bash
# Override specific repository base URLs
NUXT_PUBLIC_REPOSITORIES_POSTS_FETCH_OPTIONS_BASE_URL=https://api.example.com
NUXT_PUBLIC_REPOSITORIES_AUTHORS_FETCH_OPTIONS_BASE_URL=https://authors-api.example.com
```

---

## Using Repositories

### Basic Usage

```typescript
// Get typed repository instance
const postApi = useRepository('posts')  // Returns PostRepository

// List all
const { data: posts } = await postApi.list()

// Get single
const { data: post } = await postApi.get('ulid123')

// Create
const { data: newPost } = await postApi.create({
  title: 'Hello World',
  content: 'My first post',
})

// Update
const { data: updated } = await postApi.update('ulid123', {
  title: 'Updated Title',
})

// Delete
await postApi.delete('ulid123')
```

### With Query Parameters

```typescript
// Include relations
const { data: posts } = await postApi.list({
  include: 'author,comments',
})

// With filters
const { data: posts } = await postApi.list({
  filter: {
    status: 'published',
    'is-draft': false,
  },
})

// Pagination
const { data: posts } = await postApi.list({
  page: { number: 1, size: 25 },
})

// Combined
const { data: posts } = await postApi.list({
  include: 'author',
  filter: { status: 'published' },
  page: { number: 1, size: 25 },
})
```

### Custom Methods

```typescript
const postApi = useRepository('posts')

// Use custom method
const { data: authorPosts } = await postApi.listByAuthor('author-ulid')

const commentApi = useRepository('comments')
const { data: comment } = await commentApi.createForPost('post-ulid', { content: 'Great post!' })
```

---

## More Repository Examples

### AuthorRepository

```typescript
// app/repositories/AuthorRepository.ts
import { BaseRepository } from '#layers/base/app/repositories/base-repository'
import { ModelHydrator } from '#layers/base/app/repositories/hydrators/model-hydrator'
import Author from '~/models/Author'

export default class AuthorRepository extends BaseRepository<Author> {
  protected resource = '/api/authors'
  protected hydration = true
  protected hydrator = new ModelHydrator(Author)

  // Search authors by term
  async search(searchTerm: string) {
    return this.jsonGet(`${this.resource}/search`, {
      filter: { search: searchTerm },
    })
  }
}
```

### CommentRepository

```typescript
// app/repositories/CommentRepository.ts
import { BaseRepository } from '#layers/base/app/repositories/base-repository'
import { ModelHydrator } from '#layers/base/app/repositories/hydrators/model-hydrator'
import Comment from '~/models/Comment'

export default class CommentRepository extends BaseRepository<Comment> {
  protected resource = '/api/comments'
  protected hydration = true
  protected hydrator = new ModelHydrator(Comment)

  // Create comment for a post
  async createForPost(postUlid: string, data: { content: string }) {
    return this.jsonPost(`/api/posts/${postUlid}/comments`, data)
  }

  // Approve comment
  async approve(comment: Comment) {
    return this.jsonPost(`${this.resource}/${comment.ulid}/approve`)
  }
}
```

### TagRepository

```typescript
// app/repositories/TagRepository.ts
import { BaseRepository } from '#layers/base/app/repositories/base-repository'
import { ModelHydrator } from '#layers/base/app/repositories/hydrators/model-hydrator'
import Tag from '~/models/Tag'

export default class TagRepository extends BaseRepository<Tag> {
  protected resource = '/api/tags'
  protected hydration = true
  protected hydrator = new ModelHydrator(Tag)

  // No custom methods - uses standard CRUD
}
```

---

## Response Types

### DataResponse

Single item response:

```typescript
interface DataResponse<T> {
  data: T
}

const { data: post } = await postApi.get('ulid')
// post is typed as Post
```

### CollectionResponse

Array response:

```typescript
interface CollectionResponse<T> {
  data: T[]
}

const { data: posts } = await postApi.list()
// posts is typed as Post[]
```

### PaginatedResponse

Paginated array response:

```typescript
interface PaginatedResponse<T> {
  data: T[]
  meta: {
    current_page: number
    from: number
    last_page: number
    per_page: number
    to: number
    total: number
  }
  links: {
    first: string
    last: string
    prev: string | null
    next: string | null
  }
}
```

---

## Directory Structure

```
app/
└── repositories/
    ├── PostRepository.ts
    ├── AuthorRepository.ts
    ├── CommentRepository.ts
    └── TagRepository.ts
```

---

## Naming Conventions

| Convention | Example |
|------------|---------|
| File name | PascalCase + "Repository": `PostRepository.ts` |
| Class name | PascalCase + "Repository": `PostRepository` |
| Config key | camelCase plural: `posts`, `comments` |
| Resource path | kebab-case: `/api/posts` |

---

## Related Skills

- **[nuxt-models](../../nuxt-models/SKILL.md)** - Models for hydration
- **[nuxt-features](../../nuxt-features/SKILL.md)** - Using repositories in queries/mutations
- **[nuxt-config](../../nuxt-config/SKILL.md)** - Repository configuration
