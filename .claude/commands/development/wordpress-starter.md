---
description: Start a headless WordPress project by interviewing you about requirements and generating a project plan
---

Launches the **WordPress Starter** skill to conduct a conversational interview about your headless WordPress project.

## What This Command Does

1. **Asks 17 questions** across 6 sections:
   - Project Context (type, goals, migration status)
   - Content Architecture (content types, custom fields, update frequency)
   - API Strategy (GraphQL vs REST, preview needs)
   - Frontend Framework (team experience, rendering strategy)
   - Hosting & Deployment (WordPress hosting, frontend hosting, rebuilds)
   - Project Complexity (timeline, budget)

2. **Generates a project configuration JSON** with all decisions

3. **Creates a markdown summary** with:
   - Architecture diagram
   - Tech stack recommendations with rationale
   - Plugin list with purposes
   - Implementation checklist organized by phase

4. **Saves outputs** to `context/projects/wordpress-[projectname]-config.json` and `context/projects/wordpress-[projectname]-summary.md`

## Usage

```
/wordpress-starter
```

No arguments needed. The skill will guide you through the interview conversationally.

## When to Use

- Starting a new headless WordPress project
- Evaluating architecture decisions for WordPress + frontend
- Comparing API strategies (GraphQL vs REST)
- Generating implementation checklists

## Interview Takes ~5-10 Minutes

The skill asks questions one at a time, provides recommendations based on your responses, and explains the rationale for each suggestion.
