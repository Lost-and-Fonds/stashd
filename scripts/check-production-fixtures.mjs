import fs from 'node:fs'
import path from 'node:path'

const sourceRoot = path.resolve('frontend/src')
const sourceExtensions = new Set(['.js', '.jsx', '.ts', '.tsx', '.vue'])
const excludedPath = /(?:^|[\\/])(?:test|tests)(?:[\\/]|$)|(?:\.(?:test|spec))\.[^.]+$/i
const importPattern = /(?:\bfrom\s*|\bimport\s*\(|\brequire\s*\()(['"])([^'"]+)\1/g
const forbiddenSegment = /^(?:fixture|fixtures|mock|mocks|demo|demos|sample|samples)$/i

function filesIn(directory) {
  return fs.readdirSync(directory, { withFileTypes: true }).flatMap((entry) => {
    const entryPath = path.join(directory, entry.name)
    if (entry.isDirectory()) return filesIn(entryPath)
    return sourceExtensions.has(path.extname(entry.name)) && !excludedPath.test(entryPath)
      ? [entryPath]
      : []
  })
}

const violations = []
for (const file of filesIn(sourceRoot)) {
  const source = fs.readFileSync(file, 'utf8')
  for (const match of source.matchAll(importPattern)) {
    const segments = match[2].split(/[\\/]/).filter(Boolean)
    if (segments.some((segment) => forbiddenSegment.test(segment))) {
      const line = source.slice(0, match.index).split('\n').length
      violations.push(`${path.relative(process.cwd(), file)}:${line} imports ${match[2]}`)
    }
  }
}

if (violations.length) {
  console.error('Production frontend fixture/mock/demo imports found:')
  console.error(violations.join('\n'))
  process.exitCode = 1
} else {
  console.log('No fixture/mock/demo imports found in frontend/src.')
}
