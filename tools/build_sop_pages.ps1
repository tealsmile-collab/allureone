$ErrorActionPreference = 'Stop'
Add-Type -AssemblyName System.Drawing

$root = Split-Path -Parent $PSScriptRoot
$srcDir = Join-Path $root 'lead_config'
$outDir = Join-Path $root 'storage\sop'
New-Item -ItemType Directory -Force -Path $outDir | Out-Null

function Get-DocxParagraphs([string]$path) {
    Add-Type -AssemblyName System.IO.Compression.FileSystem
    $zip = [System.IO.Compression.ZipFile]::OpenRead($path)
    try {
        $entry = $zip.Entries | Where-Object { $_.FullName -eq 'word/document.xml' }
        $reader = New-Object System.IO.StreamReader($entry.Open())
        $xml = $reader.ReadToEnd()
        $reader.Close()
    } finally {
        $zip.Dispose()
    }
    $blocks = @()
    foreach ($match in [regex]::Matches($xml, '(?s)<w:p[ >].*?</w:p>')) {
        $chunk = $match.Value
        $style = ''
        $styleMatch = [regex]::Match($chunk, '<w:pStyle[^>]*w:val="([^"]+)"')
        if ($styleMatch.Success) { $style = $styleMatch.Groups[1].Value }
        $isList = $chunk -match '<w:numPr>'
        $parts = [regex]::Split($chunk, '<w:br[^>]*/>')
        foreach ($part in $parts) {
            $texts = [regex]::Matches($part, '(?s)<w:t[^>]*>(.*?)</w:t>') | ForEach-Object { $_.Groups[1].Value }
            $text = [System.Net.WebUtility]::HtmlDecode(($texts -join ''))
            $text = ($text -replace '\s+', ' ').Trim()
            if ($text -eq '') { continue }
            $pieces = @($text)
            $bulletCount = ([regex]::Matches($text, '•')).Count
            if ($bulletCount -gt 1) {
                $pieces = @($text -split '•' | ForEach-Object { $_.Trim() } | Where-Object { $_ -ne '' } | ForEach-Object { "• $_" })
            }
            foreach ($piece in $pieces) {
                $kind = 'p'
                if ($style -match 'Heading\s*1' -or $piece -match '^\d+\.\s+\S') { $kind = 'h1' }
                elseif ($style -match 'Heading' -or $piece -match '^\d+\.\d+') { $kind = 'h2' }
                elseif ($isList -or $piece.StartsWith('•') -or $piece.StartsWith('- ')) { $kind = 'li' }
                $blocks += [pscustomobject]@{ kind = $kind; text = $piece }
            }
        }
    }
    return $blocks
}

function Get-IndexChapters([string]$path) {
    $lines = Get-DocxParagraphs $path
    $chapters = @()
    $current = $null
    foreach ($line in $lines) {
        $text = $line.text
        if ($text -match '^(\d+)\.\s+(.+)$' -and $text -notmatch '^\d+\.\d+') {
            if ($null -ne $current) { $chapters += $current }
            $current = [ordered]@{ n = [int]$Matches[1]; title = $Matches[2].Trim(); sections = @() }
        } elseif ($text -match '^(\d+)\.(\d+)\s+(.+)$' -and $null -ne $current) {
            $current.sections += [ordered]@{ n = "$($Matches[1]).$($Matches[2])"; title = $Matches[3].Trim(); page = 1 }
        }
    }
    if ($null -ne $current) { $chapters += $current }
    return $chapters
}

$pageW = 1100
$pageH = 1500
$margin = 72
$contentW = $pageW - ($margin * 2)
$fontBody = New-Object System.Drawing.Font('Calibri', 18)
$fontH1 = New-Object System.Drawing.Font('Calibri', 26, [System.Drawing.FontStyle]::Bold)
$fontH2 = New-Object System.Drawing.Font('Calibri', 20, [System.Drawing.FontStyle]::Bold)
$fontSmall = New-Object System.Drawing.Font('Calibri', 12)
$brush = [System.Drawing.Brushes]::Black
$muted = New-Object System.Drawing.SolidBrush ([System.Drawing.Color]::FromArgb(90, 98, 110))
$measureBmp = New-Object System.Drawing.Bitmap 10, 10
$measure = [System.Drawing.Graphics]::FromImage($measureBmp)
$measure.TextRenderingHint = [System.Drawing.Text.TextRenderingHint]::AntiAliasGridFit

function Get-Lines([string]$text, [System.Drawing.Font]$font, [int]$width) {
    $words = @($text -split '\s+' | Where-Object { $_ -ne '' })
    if ($words.Count -eq 0) { return @('') }
    $lines = @()
    $current = ''
    foreach ($word in $words) {
        $trial = if ($current -eq '') { $word } else { "$current $word" }
        $size = $measure.MeasureString($trial, $font)
        if ($size.Width -le $width) {
            $current = $trial
        } else {
            if ($current -ne '') { $lines += $current }
            $current = $word
        }
    }
    if ($current -ne '') { $lines += $current }
    return $lines
}

$chapters = Get-IndexChapters (Join-Path $srcDir 'SOP Index.docx')
$catalog = @()

foreach ($chapter in $chapters) {
    $match = Get-ChildItem -Path $srcDir -Filter "$($chapter.n). *.docx" | Select-Object -First 1
    $title = "$($chapter.n). $($chapter.title)"
    if (-not $match) {
        $catalog += [ordered]@{ id = [string]$chapter.n; title = $title; pages = 0; sections = @($chapter.sections) }
        continue
    }
    Write-Output "Rendering $($match.Name)"
    $blocks = Get-DocxParagraphs $match.FullName
    $items = @()
    foreach ($block in $blocks) {
        $font = $fontBody
        $prefix = ''
        $gap = 12
        $lineH = 34
        if ($block.kind -eq 'h1') { $font = $fontH1; $gap = 18; $lineH = 40 }
        elseif ($block.kind -eq 'h2') { $font = $fontH2; $gap = 14; $lineH = 36 }
        elseif ($block.kind -eq 'li') { $prefix = '• '; $lineH = 34 }
        $lines = Get-Lines ($(if ($block.kind -eq 'li' -and -not $block.text.StartsWith('•')) { '• ' } else { '' }) + $block.text) $font ($contentW - 8)
        foreach ($line in $lines) {
            $items += [pscustomobject]@{ text = $line; font = $font; lineH = $lineH; gap = 0; kind = $block.kind }
        }
        if ($items.Count -gt 0) { $items[$items.Count - 1].gap = $gap }
    }

    $pages = @()
    $currentPage = @()
    $y = $margin + 8
    $bottom = $pageH - $margin
    foreach ($item in $items) {
        $need = $item.lineH + $item.gap
        if (($y + $need) -gt $bottom -and $currentPage.Count -gt 0) {
            $pages += ,$currentPage
            $currentPage = @()
            $y = $margin + 8
        }
        $currentPage += $item
        $y += $need
    }
    if ($currentPage.Count -gt 0) { $pages += ,$currentPage }
    if ($pages.Count -eq 0) { $pages = ,@() }

    $sectionPages = @{}
    $pageNo = 1
    foreach ($pageItems in $pages) {
        foreach ($item in $pageItems) {
            foreach ($section in $chapter.sections) {
                $key = $section.n
                if ($sectionPages.ContainsKey($key)) { continue }
                $hay = ($item.text -replace '[^a-z0-9]+', ' ').ToLower()
                $needle = ("$($section.n) $($section.title)" -replace '[^a-z0-9]+', ' ').ToLower().Trim()
                if ($hay.Contains($needle) -or $hay.Contains(($section.title -replace '[^a-z0-9]+', ' ').ToLower().Trim())) {
                    $sectionPages[$key] = $pageNo
                }
            }
        }
        $pageNo++
    }
    foreach ($section in $chapter.sections) {
        if ($sectionPages.ContainsKey($section.n)) { $section.page = [int]$sectionPages[$section.n] }
    }

    $chapterDir = Join-Path $outDir ([string]$chapter.n)
    if (Test-Path $chapterDir) { Remove-Item $chapterDir -Recurse -Force }
    New-Item -ItemType Directory -Force -Path $chapterDir | Out-Null
    $pageNo = 1
    foreach ($pageItems in $pages) {
        $bmp = New-Object System.Drawing.Bitmap $pageW, $pageH
        $g = [System.Drawing.Graphics]::FromImage($bmp)
        $g.Clear([System.Drawing.Color]::White)
        $g.TextRenderingHint = [System.Drawing.Text.TextRenderingHint]::AntiAliasGridFit
        $g.DrawString($title, $fontSmall, $muted, $margin, 28)
        $g.DrawString("$pageNo / $($pages.Count)", $fontSmall, $muted, ($pageW - $margin - 80), 28)
        $y = $margin + 8
        foreach ($item in $pageItems) {
            $g.DrawString($item.text, $item.font, $brush, $margin, $y)
            $y += $item.lineH + $item.gap
        }
        $outFile = Join-Path $chapterDir ("p{0:D3}.png" -f $pageNo)
        $bmp.Save($outFile, [System.Drawing.Imaging.ImageFormat]::Png)
        $g.Dispose()
        $bmp.Dispose()
        $pageNo++
    }
    $catalog += [ordered]@{
        id = [string]$chapter.n
        title = $title
        pages = $pages.Count
        sections = @($chapter.sections)
    }
    Write-Output "  pages=$($pages.Count)"
}

$measure.Dispose()
$measureBmp.Dispose()
$fontBody.Dispose()
$fontH1.Dispose()
$fontH2.Dispose()
$fontSmall.Dispose()
$muted.Dispose()

$json = $catalog | ConvertTo-Json -Depth 6
[System.IO.File]::WriteAllText((Join-Path $outDir 'catalog.json'), $json, [System.Text.UTF8Encoding]::new($false))
Write-Output 'Wrote catalog'
