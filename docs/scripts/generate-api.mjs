import { readFileSync, writeFileSync, mkdirSync, readdirSync, rmSync } from 'node:fs'
import { dirname, join } from 'node:path'
import { fileURLToPath } from 'node:url'

const scriptsDir = dirname(fileURLToPath(import.meta.url))
const docsDir = join(scriptsDir, '..')

const REPO_BLOB = {
    'Rasuvaeff\\Mcp': 'https://github.com/rasuvaeff/mcp/blob/master/',
    'Rasuvaeff\\Mcp\\OpenApi': 'https://github.com/rasuvaeff/mcp-openapi/blob/master/',
    'Rasuvaeff\\Yii3Mcp': 'https://github.com/rasuvaeff/yii3-mcp/blob/master/',
    'Rasuvaeff\\Yii3McpAuditLogBridge': 'https://github.com/rasuvaeff/yii3-mcp-audit-log-bridge/blob/master/',
    'Rasuvaeff\\Yii3McpRbacBridge': 'https://github.com/rasuvaeff/yii3-mcp-rbac-bridge/blob/master/',
    'Rasuvaeff\\Yii3McpTelemetryBridge': 'https://github.com/rasuvaeff/yii3-mcp-telemetry-bridge/blob/master/',
    'Rasuvaeff\\LaravelMcp': 'https://github.com/rasuvaeff/laravel-mcp/blob/master/',
    'Rasuvaeff\\SymfonyMcp': 'https://github.com/rasuvaeff/symfony-mcp/blob/master/',
}

function sourcePath(className, namespacePrefix) {
    return className.slice(namespacePrefix.length + 1).replaceAll('\\', '/') + '.php'
}

function shortName(className) {
    const parts = className.split('\\')
    return parts[parts.length - 1]
}

// Two packages may each have a class of one short name (the adapters' `Guard`):
// such a page takes its package's namespace part as a prefix, `LaravelMcpGuard`.
function pageNames(entries) {
    const count = new Map()
    for (const entry of entries) {
        count.set(shortName(entry.class), (count.get(shortName(entry.class)) ?? 0) + 1)
    }

    return new Map(
        entries.map((entry) => {
            const name = shortName(entry.class)
            const prefix = entry.package.split('\\').slice(1).join('')

            return [entry.class, count.get(name) > 1 ? prefix + name : name]
        }),
    )
}

function shortenType(type) {
    if (type === undefined || type === null) {
        return 'mixed'
    }
    return type.replaceAll(/Rasuvaeff\\(Mcp\\OpenApi|Mcp|Yii3Mcp(AuditLogBridge|RbacBridge|TelemetryBridge)?|LaravelMcp|SymfonyMcp)\\/g, '')
}

function formatParams(params) {
    return params.map((p) => `${shortenType(p.type)} $${p.name}`).join(', ')
}

function methodSignature(method) {
    const prefix = method.static ? 'static ' : ''
    const returns = shortenType(method.returnType)
    return `${prefix}${method.name}(${formatParams(method.params)}): ${returns}`
}

// A fenced ```php block (real syntax highlighting) reads far better than an
// inline-code heading, especially once a signature has enough params to wrap
// mid-identifier — mirror this codebase's own style for a long parameter
// list (one per line, trailing comma) rather than a single unbroken line.
function methodSignatureBlock(method) {
    const oneLine = methodSignature(method)
    if (method.params.length <= 2 && oneLine.length <= 88) {
        return oneLine
    }

    const prefix = method.static ? 'static ' : ''
    const returns = shortenType(method.returnType)
    const params = method.params.map((p) => `    ${shortenType(p.type)} $${p.name},`).join('\n')

    return `${prefix}${method.name}(\n${params}\n): ${returns}`
}

// A meta <description> wants one clean sentence, not a multi-paragraph
// docblock verbatim — collapse whitespace/newlines and cut at the first
// sentence boundary within a reasonable length, falling back to a hard
// truncation only for a single very long unbroken sentence.
function firstSentence(text, maxLen) {
    const flat = text.replace(/\s+/g, ' ').trim()
    if (flat === '') return ''
    const boundary = flat.slice(0, maxLen + 1).search(/[.!?](\s|$)/)
    if (boundary !== -1 && boundary <= maxLen) return flat.slice(0, boundary + 1)
    if (flat.length <= maxLen) return flat
    const truncated = flat.slice(0, maxLen - 1)
    const lastSpace = truncated.lastIndexOf(' ')
    return (lastSpace > 0 ? truncated.slice(0, lastSpace) : truncated).trimEnd() + '…'
}

// Docblock prose is markdown-hostile as-is: a summary line starting with a
// word VitePress's markdown-it could mistake for a directive, or containing
// bare `<`/`>` (generic types, comparisons), can break rendering. Escaping
// isn't needed for the common case here (prose, inline `` `code` ``,
// backtick-quoted identifiers) — MCP docblocks in this codebase don't use
// raw angle brackets in prose — so this stays a straight passthrough with
// paragraph breaks preserved.
function renderProse(text) {
    return text
        .split('\n\n')
        .map((p) => p.trim())
        .filter(Boolean)
        .join('\n\n')
}

function renderClass(entry) {
    const name = shortName(entry.class)
    const src = REPO_BLOB[entry.package] + 'src/' + sourcePath(entry.class, entry.package)
    const kindLabel = { class: 'Class', interface: 'Interface', enum: 'Enum' }[entry.kind]
    const kindLower = { class: 'class', interface: 'interface', enum: 'enum' }[entry.kind]
    const fallbackDescription = `${name} — ${kindLower} in the ${packageSlug(entry.package)} package of the PHP MCP (Model Context Protocol) server API reference.`
    const description = entry.summary ? firstSentence(entry.summary, 155) : fallbackDescription
    const banner = `<!-- AUTO-GENERATED by docs/scripts/generate-api.mjs from a reflection pass over src/ (docs/scripts/reflect-api.php) — do not edit this file directly. -->`

    const lines = []
    lines.push('---')
    lines.push(`title: "${name}"`)
    lines.push(`description: ${JSON.stringify(description)}`)
    lines.push('---')
    lines.push('')
    lines.push(banner)
    lines.push('')
    lines.push(`# \`${name}\``)
    lines.push('')
    lines.push(`\`${entry.class}\``)
    lines.push('')
    lines.push(
        `**${kindLabel}** — [\`${entry.package}\`](/packages/${packageSlug(entry.package)}) — [Source](${src})` +
            (entry.extends ? ` — **Extends:** \`${shortenType(entry.extends)}\`` : ''),
    )
    lines.push('')

    if (entry.summary) {
        lines.push(renderProse(entry.summary))
        lines.push('')
    }

    if (entry.publicProperties.length > 0) {
        lines.push('## Properties')
        lines.push('')
        lines.push('| Property | Type | Readonly |')
        lines.push('|---|---|---|')
        for (const prop of entry.publicProperties) {
            lines.push(`| \`${prop.name}\` | \`${shortenType(prop.type)}\` | ${prop.readonly ? 'yes' : 'no'} |`)
        }
        lines.push('')
    }

    if (entry.publicMethods.length > 0) {
        lines.push('## Methods')
        lines.push('')
        for (const method of entry.publicMethods) {
            lines.push(`### ${method.name}()`)
            lines.push('')
            lines.push('```php')
            lines.push(methodSignatureBlock(method))
            lines.push('```')
            lines.push('')
            if (method.summary) {
                lines.push(renderProse(method.summary))
                lines.push('')
            }
            const documentedParams = method.params.filter((p) => p.description !== '')
            if (documentedParams.length > 0) {
                for (const p of documentedParams) {
                    lines.push(`- \`$${p.name}\` — ${p.description}`)
                }
                lines.push('')
            }
        }
    }

    if (entry.publicProperties.length === 0 && entry.publicMethods.length === 0) {
        lines.push('No public properties or methods beyond the constructor.')
        lines.push('')
    }

    return lines.join('\n')
}

function packageSlug(namespace) {
    return (
        {
            'Rasuvaeff\\Mcp': 'mcp',
            'Rasuvaeff\\Mcp\\OpenApi': 'mcp-openapi',
            'Rasuvaeff\\Yii3Mcp': 'yii3-mcp',
            'Rasuvaeff\\Yii3McpAuditLogBridge': 'audit-log-bridge',
            'Rasuvaeff\\Yii3McpRbacBridge': 'rbac-bridge',
            'Rasuvaeff\\Yii3McpTelemetryBridge': 'telemetry-bridge',
            'Rasuvaeff\\LaravelMcp': 'laravel-mcp',
            'Rasuvaeff\\SymfonyMcp': 'symfony-mcp',
        }[namespace] ?? namespace
    )
}

function clean(dir) {
    try {
        for (const entry of readdirSync(dir)) {
            rmSync(join(dir, entry), { recursive: true, force: true })
        }
    } catch {
        // directory does not exist yet
    }
}

const snapshot = JSON.parse(readFileSync(join(scriptsDir, 'api-snapshot.json'), 'utf8'))
const apiEntries = snapshot.filter((entry) => entry.isApi)

const outDir = join(docsDir, 'api', 'classes')
mkdirSync(outDir, { recursive: true })
clean(outDir)
const pages = pageNames(apiEntries)
for (const entry of apiEntries) {
    const outPath = join(outDir, `${pages.get(entry.class)}.md`)
    writeFileSync(outPath, renderClass(entry) + '\n', 'utf8')
}

console.log(`Generated ${apiEntries.length} API class pages into docs/api/classes/**.`)
