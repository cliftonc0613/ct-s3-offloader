---
name: design-polish
description: Complete design review and implementation workflow. Reviews current design using design-review-agent, identifies premium enhancement opportunities with premium-ui-designer, presents improvements for user approval, then implements approved changes with frontend-developer.
---

# Design Polish - Comprehensive Review & Enhancement Workflow

Review your design, identify improvement opportunities, and implement them with expert guidance.

## Usage

```bash
# Review current project
/design-polish

# Review specific URL
/design-polish http://localhost:3000

# Review specific components
/design-polish src/components/Button.tsx src/pages/Home.tsx
```

## What It Does

The `/design-polish` command orchestrates a complete design improvement workflow:

### Phase 1: Comprehensive Design Review
The **design-review-agent** analyzes your design using Playwright:
- Tests across desktop (1440px), tablet (768px), and mobile (375px) viewports
- Captures screenshots for visual reference
- Tests keyboard navigation and focus states
- Verifies WCAG accessibility compliance
- Checks browser console for errors
- Categorizes findings by severity

### Phase 2: Premium Enhancement Analysis
The **premium-ui-designer** identifies enhancement opportunities:
- Analyzes typography hierarchy and readability
- Identifies animation and micro-interaction opportunities
- Suggests visual hierarchy improvements
- Recommends premium color and shadow treatments
- Proposes sophisticated layout refinements
- Prioritizes by impact and effort

### Phase 3: User Review & Selection
The command presents a categorized improvement checklist:
```
🔴 Blockers (Critical - must fix)
🟠 High Priority (Significant issues)
✨ Premium Enhancements (UI polish opportunities)
💡 Nitpicks (Minor improvements)
```

You review the findings and select which improvements to implement.

### Phase 4: Implementation
The **frontend-developer** agent implements approved improvements:
- Modifies components with proper architecture
- Updates React hooks and state management as needed
- Applies responsive CSS and Tailwind changes
- Adds accessibility attributes
- Optimizes performance
- Writes tests for modified components

### Phase 5: Summary & Verification
The command provides:
- List of modified files
- Summary of implemented improvements
- Option to run design-polish again to verify
- Option to create a git commit for changes

## Output Files

All design review work is organized in the `design-reviews/` directory:

```
design-reviews/
├── review-2024-11-27-143022.md      # Initial design review findings
├── enhancements-2024-11-27-143022.md # Premium enhancement opportunities
├── approved-2024-11-27-143022.md    # User-approved improvements
└── summary-2024-11-27-143022.md     # Implementation summary
```

These files are saved for:
- Future reference and tracking
- Understanding what was improved
- Comparing against future reviews
- Documenting design decisions

## Key Features

### Smart Input Handling
- **URL provided**: Reviews the live environment using Playwright
- **File paths provided**: Analyzes components + infers preview environment
- **No input**: Auto-detects running dev server or current directory

### Progressive Enhancement
Improvements are organized by impact:
1. **Blockers first** - Fix broken functionality
2. **High priority** - Resolve UX issues
3. **Premium enhancements** - Add polish and delight
4. **Nitpicks** - Minor refinements

### User Control
Before implementation, you:
- Review all identified issues
- See recommendations from design experts
- Choose which improvements to implement
- Control implementation scope

### Batch Implementation
- Improvements are implemented incrementally
- Brief feedback between changes
- Easy to see what changed and why
- Smaller, focused commits

## Examples

### Basic Design Review
```bash
/design-polish
```
Reviews current project, identifies improvements, asks which to implement.

### Review Specific URL
```bash
/design-polish http://localhost:3000/dashboard
```
Reviews specific page in live environment with Playwright testing.

### Review Components
```bash
/design-polish src/components/Card.tsx src/components/Button.tsx
```
Focuses review on specific component files.

## Advanced Features

### Accessibility Focus
Design reviews prioritize WCAG 2.1 AA compliance:
- Keyboard navigation testing
- Focus state visibility
- Color contrast verification
- Semantic HTML validation
- ARIA attribute checks

### Responsive Design Testing
Automatic viewport testing:
- **Desktop**: 1440px (full experience)
- **Tablet**: 768px (layout adaptation)
- **Mobile**: 375px (touch optimization)

### Performance Awareness
Recommendations consider:
- Animation performance
- CSS optimization
- Component render efficiency
- Loading state handling

### Visual Evidence
Design reviews include:
- Full-page screenshots per viewport
- Marked-up issue areas
- Before/after comparison suggestions
- Console error documentation

## Workflow Tips

### Getting Better Results
1. **Provide context**: Use URLs when possible for accurate Playwright testing
2. **Start with blockers**: Fix critical issues before polish
3. **Review incrementally**: Run design-polish multiple times as you iterate
4. **Track changes**: Keep design-review files to see progress over time

### When to Use
- After building new features
- Before shipping to production
- During design refinement phases
- When polishing user experience
- To verify accessibility compliance

### What Not to Use For
- Architecture or feature decisions (use separate design reviews)
- Performance profiling (use performance tools)
- Content editing (use content management)
- Database schema changes (use data modeling tools)

## Expected Output

After running `/design-polish`, you'll get:

1. **Design Review Report** - All findings categorized by severity
2. **Enhancement Recommendations** - Specific opportunities for improvement
3. **User Approval Checklist** - Select which improvements to implement
4. **Implementation Summary** - Files modified and changes made
5. **Saved Documents** - Full history in design-reviews/ folder

## Troubleshooting

### Design review can't connect to preview
Make sure your dev server is running on localhost or provide the URL:
```bash
/design-polish http://your-actual-url.com
```

### No improvements suggested
This usually means the design is in great shape! Consider:
- Running accessibility tests with tools like aXe
- Comparing with industry-standard UI patterns
- Getting user feedback on usability

### Implementation fails
Check:
- File permissions in your project
- TypeScript/ESLint configuration compatibility
- Required dependencies are installed
- No conflicting changes were made manually

## Next Steps

After running `/design-polish`:

1. **Review the findings** - Read through categorized improvements
2. **Select improvements** - Choose what to implement now vs. later
3. **Implement changes** - Let the frontend-developer agent update components
4. **Commit changes** - Use `/git-flow` or `/commit-commands:commit` to save work
5. **Verify changes** - Run `/design-polish` again to confirm improvements

Each iteration compounds - start with critical issues, then progressively enhance the experience.

---

**Pro Tip**: Run `/design-polish` regularly during development to catch issues early and maintain design quality throughout your project.
