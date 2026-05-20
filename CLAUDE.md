# CLAUDE.md - USAFA Parents Club Website

## Project Overview

This is the website for the USAFA Parents Club of Alabama (alabamafalcons.org). The site includes leadership information, sponsorship details, membership, mentorship programs, and family resources.

## Critical Rules - DO NOT CHANGE

### Email Addresses
**NEVER modify or replace email addresses.** Current correct emails are:
- president@alabamafalcons.org
- vp@alabamafalcons.org
- secretary@alabamafalcons.org
- treasurer@alabamafalcons.org
- atlarge@alabamafalcons.org
- info@alabamafalcons.org
- siteadmin@alabamafalcons.org

All emails must use direct `mailto:` links, NOT Cloudflare email protection.

### USAFA News Section
**DO NOT modify the loadNews() function or cached news data.**
- Function location: index.html, line ~643
- The function includes fallback cached data for when PHP is unavailable
- Fallback data contains 4 USAFA news items with proper formatting
- The renderNews() function at line ~627 handles display

### Navigation Bar
**ALL HTML files must have identical navigation bars** across the top:
- Logo: logo01.png
- Brand text: "USAFA Parents Club" with small text "of Alabama"
- 8 Navigation links in order: About, Programs, Membership, Leadership, Events, News, Sponsors, Contact
- Alabama flag SVG at the far right end
- Hamburger menu for mobile with id="hamburger" and aria-label="Menu"
- nav-links list must have id="nav-links"

**HTML files that must have matching nav:**
- index.html
- sponsorship.html
- membership.html
- familyresources.html
- liaison.html
- mentorship.html
- payment.html
- president-letter.html

## Key File Locations

### Primary Files
- `index.html` - Main landing page with leadership, news, events, membership info
- `sponsorship.html` - Corporate and individual sponsor showcase
- `.claude/launch.json` - Dev server config (uses full Python path)

### Supporting Pages
- `membership.html` - Membership application
- `familyresources.html` - Family resources and information
- `liaison.html` - Cadet liaison program
- `mentorship.html` - Parent mentorship program
- `payment.html` - Payment/donation page
- `president-letter.html` - President's welcome letter

### Backend Files
- `usafa-news.php` - Fetches USAFA RSS feed and caches results
- `usafa-news-cache.json` - Fallback news data (30-minute cache)
- `membership-handler.php` - Form submission handler
- `contact-form.php` - Contact form handler

### Assets
- `logo01.png` - Club logo

## Deployment

The website is hosted on GitHub Pages at:
- GitHub Pages: https://mijohnst.github.io/usafa-alabama-parents/
- Production: https://alabamafalcons.org/

When deploying:
1. Update local files in this directory
2. Copy files to the GitHub repository
3. Push to GitHub to deploy

## Important Technical Notes

### News Section
- The loadNews() function attempts to fetch from `/usafa-news.php` first
- If PHP fetch fails (e.g., on static hosting), it falls back to embedded cached data
- Cached data is embedded directly in the HTML to ensure news always displays
- Never remove the fallback data

### Navigation
- All pages use the same navigation structure for consistency
- The Alabama flag is a final `<li>` item with inline styles
- Mobile hamburger menu uses CSS for responsive behavior

### Style Variables
- Navy blue: #003594 (--navy)
- Top bar: #002554 (--topbar)
- Silver: #8A8D8F (--silver)
- Fonts: Cinzel (display), Source Sans 3 (body)

## Leadership Section Notes

Officer photos are embedded as base64 data URIs in the HTML. Current officers:
- President
- VP
- Secretary
- Treasurer
- Member at Large
- Tony Kim (with bio about retired AF, Maxwell AFB, Navy daughter, USAFA '28 son Ian Kim)

## Do NOT
- Auto-correct or change capitalization of "of Alabama" in the brand
- Modify email addresses without explicit instruction
- Remove or simplify the USAFA News fallback mechanism
- Change navigation structure or link order across pages
- Remove the Alabama flag from the navigation

## Do
- Keep all HTML files with consistent styling and structure
- Ensure responsive design works on mobile
- Test that all mailto: links work correctly
- Verify news section displays properly (with or without PHP)
- Maintain consistent spacing and typography across pages
