# Table Patterns

## Column Builder Utility

Create reusable column configurations:

```typescript
// app/utils/createColumnBuilder.ts
import type { TableColumn } from '@tanstack/vue-table'

export interface ColumnBuilder<TModel> {
  all(): TableColumn<TModel>[]
  build(columns: string[]): TableColumn<TModel>[]
  except(columnsToExclude: string[]): TableColumn<TModel>[]
}

export function createColumnBuilder<TModel>(
  columns: Record<string, TableColumn<TModel>>
): ColumnBuilder<TModel> {
  return {
    all: () => Object.values(columns),
    build: (columnKeys: string[]) =>
      columnKeys.map(key => columns[key]).filter(Boolean),
    except: (columnsToExclude: string[]) =>
      Object.keys(columns)
        .filter(key => !columnsToExclude.includes(key))
        .map(key => columns[key]),
  }
}
```

---

## Complete Table Configuration

```typescript
// app/tables/posts.ts
import { h } from 'vue'
import type { TableColumn } from '@tanstack/vue-table'
import type Post from '~/models/Post'
import Copyable from '~/components/Common/Copyable.vue'
import { truncateMiddle } from '#layers/base/app/utils'

// ULID column with copy functionality
const ulidColumn: TableColumn<Post> = {
  id: 'ulid',
  accessorKey: 'ulid',
  header: 'ULID',
  cell: ({ row }) => h(Copyable, { content: row.original.ulid }, () =>
    truncateMiddle(row.original.ulid, 8)
  ),
}

// Status column with badge
const statusColumn: TableColumn<Post> = {
  id: 'status',
  accessorKey: 'status',
  header: 'Status',
  cell: ({ row }) => h(UBadge, {
    color: row.getValue('status').color(),
  }, () => row.getValue('status').text),
}

// Author column with link
const authorColumn: TableColumn<Post> = {
  id: 'author',
  accessorKey: 'author.name',
  header: 'Author',
  cell: ({ row }) => h(NuxtLink, {
    to: `/users/${row.original.author.ulid}`,
    class: 'text-primary-500 hover:underline',
  }, () => row.original.author.name),
}

// Title column
const titleColumn: TableColumn<Post> = {
  id: 'title',
  accessorKey: 'title',
  header: 'Title',
  cell: ({ row }) => row.original.title,
}

// Content column (truncated)
const contentColumn: TableColumn<Post> = {
  id: 'content',
  accessorKey: 'content',
  header: 'Content',
  cell: ({ row }) => h('span', {
    class: 'truncate max-w-xs',
    title: row.original.content,
  }, row.original.content),
}

// Dates column
const datesColumn: TableColumn<Post> = {
  id: 'dates',
  header: 'Created',
  cell: ({ row }) => row.original.createdAt.format('DD MMM YYYY'),
}

// Comments count column
const commentsColumn: TableColumn<Post> = {
  id: 'comments',
  header: 'Comments',
  cell: ({ row }) => row.original.commentsCount ?? 0,
}

// Draft flag column
const draftColumn: TableColumn<Post> = {
  id: 'isDraft',
  header: 'Draft',
  cell: ({ row }) => row.original.isDraft
    ? h(UBadge, { color: 'warning' }, () => 'Draft')
    : null,
}

// Export builder
export const postsColumnBuilder = createColumnBuilder<Post>({
  ulid: ulidColumn,
  title: titleColumn,
  author: authorColumn,
  status: statusColumn,
  content: contentColumn,
  comments: commentsColumn,
  isDraft: draftColumn,
  dates: datesColumn,
})
```

---

## Using Column Builder

```typescript
// All columns
const columns = postsColumnBuilder.all()

// Specific columns
const columns = postsColumnBuilder.build(['ulid', 'title', 'status'])

// Exclude columns
const columns = postsColumnBuilder.except(['ulid', 'dates'])
```

---

## XTable Component

```vue
<XTable
  :data="posts"
  :columns="columns"
  :loading="isLoading"
  :fetching="isFetching"
  :row-actions="rowActions"
  row-id="ulid"
  @row-click="handleRowClick"
  @row-select="handleRowSelect"
/>
```

### Props

| Prop | Type | Description |
|------|------|-------------|
| `data` | `T[]` | Table data array |
| `columns` | `TableColumn<T>[]` | Column definitions |
| `loading` | `boolean` | Initial loading state |
| `fetching` | `boolean` | Refetch loading state |
| `rowActions` | `(row) => Action[]` | Row action menu items |
| `rowId` | `string` | Unique identifier key |
| `selectable` | `boolean` | Enable row selection |

### Events

| Event | Payload | Description |
|-------|---------|-------------|
| `@row-click` | `{ row, event }` | Row clicked |
| `@row-select` | `{ selected: T[] }` | Selection changed |

---

## Row Actions

```typescript
import type { Row } from '@tanstack/vue-table'

const rowActions = computed(() => (row: Row<Post>) => [
  // Navigation action
  {
    label: 'View post',
    to: `/posts/${row.original.ulid}`,
  },
  // Navigation action
  {
    label: 'View author',
    to: `/users/${row.original.author.ulid}`,
  },
  // Function action
  {
    label: 'Edit post',
    onSelect: () => openUpdateSlideover({ post: row.original }),
  },
  // Destructive action
  {
    label: 'Delete post',
    onSelect: () => handleDelete([row.original]),
  },
])
```

### Conditional Actions

```typescript
const rowActions = computed(() => (row: Row<Post>) => {
  const actions = [
    { label: 'View', to: `/posts/${row.original.ulid}` },
  ]

  // Conditional edit
  if (can(UpdatePost)) {
    actions.push({
      label: 'Edit',
      onSelect: () => openEdit(row.original),
    })
  }

  // Conditional delete
  if (can(DeletePost) && row.original.status.isEditable()) {
    actions.push({
      label: 'Delete',
      onSelect: () => handleDelete(row.original),
    })
  }

  return actions
})
```

---

## Table Component Pattern

```vue
<!-- app/components/Tables/PostsTable.vue -->
<script lang="ts" setup>
import { postsColumnBuilder } from '~/tables/posts'
import type { Row } from '@tanstack/vue-table'

const props = defineProps<{
  posts: Post[]
  loading?: boolean
  fetching?: boolean
}>()

const emits = defineEmits<{
  edit: [post: Post]
  delete: [posts: Post[]]
  select: [posts: Post[]]
}>()

// Get all columns
const columns = postsColumnBuilder.all()

// Or specific columns for this context
// const columns = postsColumnBuilder.build(['title', 'status', 'dates'])

// Row actions
const rowActions = computed(() => (row: Row<Post>) => [
  { label: 'View post', to: `/posts/${row.original.ulid}` },
  { label: 'View author', to: `/users/${row.original.author.ulid}` },
  { label: 'Edit post', onSelect: () => emits('edit', row.original) },
  { label: 'Delete post', onSelect: () => emits('delete', [row.original]) },
])

// Row click handler
const handleRowClick = ({ row }: { row: Row<Post> }) => {
  navigateTo(`/posts/${row.original.ulid}`)
}
</script>

<template>
  <XTable
    :data="posts"
    :columns="columns"
    :loading="loading"
    :fetching="fetching"
    :row-actions="rowActions"
    row-id="ulid"
    @row-click="handleRowClick"
  />
</template>
```

---

## Custom Cell Renderers

### Using h() function

```typescript
import { h } from 'vue'

const statusColumn: TableColumn<Post> = {
  id: 'status',
  header: 'Status',
  cell: ({ row }) => h(UBadge, {
    color: row.original.status.color(),
  }, () => row.original.status.text),
}
```

### Complex Cell

```typescript
const actionsColumn: TableColumn<Post> = {
  id: 'actions',
  header: '',
  cell: ({ row }) => h('div', { class: 'flex gap-2' }, [
    h(UButton, {
      size: 'xs',
      variant: 'ghost',
      icon: 'i-heroicons-pencil',
      onClick: () => emits('edit', row.original),
    }),
    h(UButton, {
      size: 'xs',
      variant: 'ghost',
      color: 'error',
      icon: 'i-heroicons-trash',
      onClick: () => emits('delete', row.original),
    }),
  ]),
}
```

### Conditional Rendering

```typescript
const draftColumn: TableColumn<Post> = {
  id: 'isDraft',
  header: 'Draft',
  cell: ({ row }) => row.original.isDraft
    ? h(UBadge, { color: 'warning' }, () => 'Draft')
    : null,
}
```

---

## Directory Structure

```
app/
└── tables/
    ├── posts.ts
    ├── users.ts
    └── comments.ts
```

---

## Naming Conventions

| Type | Convention |
|------|------------|
| File | kebab-case: `posts.ts` |
| Builder export | camelCase + "ColumnBuilder": `postsColumnBuilder` |
| Column variables | camelCase + "Column": `statusColumn` |

---

## Related Skills

- **[nuxt-components](../../nuxt-components/SKILL.md)** - Table component patterns
- **[nuxt-pages](../../nuxt-pages/SKILL.md)** - Using tables in pages
