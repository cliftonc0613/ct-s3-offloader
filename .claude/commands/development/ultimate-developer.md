---
name: ultimate-developer
description: Intelligent developer assistant that analyzes user requests and automatically dispatches to the optimal combination of Design, Developer, and SEO agents based on your specific needs.
agent: frontend-developer, javascript-pro, premium-ui-designer, meta-seo-agent, content-analyzer, content-researcher, keyword-extractor, seo-auditor, Explore
model: sonnet
tools: Read, Write, Edit, Bash
color: orange
---

# Ultimate Developer - Intelligent Multi-Agent Assistant

Your all-in-one developer companion that understands what you need and routes to the right experts.

## Usage

```bash
# Simple request - command intelligently chooses agents
/ultimate-developer Build me a landing page for a SaaS product

# Complex request - handles multiple domains
/ultimate-developer Create a responsive product showcase component with SEO optimization and premium animations

# Just describe what you need
/ultimate-developer I need a blog post about React performance with keyword research and competitive analysis
```

## How It Works

The Ultimate Developer command intelligently analyzes your request and dispatches to one or more specialized agents:

### 🎨 Design & Development Agents
These agents handle building, styling, and optimizing your code:

- **frontend-developer** - React components, hooks, state management, responsive design
- **javascript-pro** - Modern JavaScript, async patterns, performance optimization
- **premium-ui-designer** - Sophisticated styling, animations, micro-interactions, premium aesthetics

**Triggers:** "build", "component", "page", "responsive", "design", "style", "animate", "interactive", "UI", "interface"

### 🔍 SEO & Content Agents
These agents handle search optimization and content strategy:

- **meta-seo-agent** - Meta tags, schema markup, structured data for Astro sites
- **content-analyzer** - Competitor analysis, trending topics, content gaps
- **content-researcher** - Research trends, newsletter analysis, industry intelligence
- **keyword-extractor** - Keyword research, opportunity discovery, SEO targeting
- **seo-auditor** - Technical SEO, performance, on-page optimization, competitive analysis

**Triggers:** "SEO", "keyword", "content", "blog", "article", "search", "ranking", "optimize", "traffic", "meta", "schema"

### 🔍 Debugging & Audit Agents
These agents handle codebase analysis and error detection:

- **Explore** - Codebase analysis, error detection, debugging, comprehensive audits

**Triggers:** "debug", "error", "fix", "audit", "issues", "problems", "bugs", "review code", "look over", "check for"

## Smart Routing Examples

### Example 1: Design + Development
**Input:** "Build me a responsive hero component with smooth animations"

**Agents Dispatched:**
1. **frontend-developer** - Create React component with responsive layout
2. **premium-ui-designer** - Add sophisticated animations and micro-interactions

### Example 2: SEO + Content
**Input:** "Create a blog post about Next.js that ranks well for SEO"

**Agents Dispatched:**
1. **content-analyzer** - Research competitor content and identify gaps
2. **keyword-extractor** - Find high-opportunity keywords
3. **seo-auditor** - Verify technical SEO implementation

### Example 3: Full Stack
**Input:** "Build a landing page for my SaaS with premium design, optimized for search engines"

**Agents Dispatched:**
1. **frontend-developer** - Create landing page structure
2. **premium-ui-designer** - Add premium aesthetics and animations
3. **keyword-extractor** - Research service keywords
4. **meta-seo-agent** - Implement meta tags and schema

### Example 4: Code Optimization
**Input:** "Optimize my React component for performance with modern JavaScript patterns"

**Agents Dispatched:**
1. **javascript-pro** - Analyze and optimize JavaScript patterns
2. **frontend-developer** - Refactor React component with hooks optimization

### Example 5: Debugging & Code Audit
**Input:** "Look over the theme and debug any errors"

**Agents Dispatched:**
1. **Explore** - Comprehensive codebase audit for errors, anti-patterns, and issues
2. **javascript-pro** - Fix JavaScript/TypeScript errors found
3. **frontend-developer** - Fix React component issues

## What Each Agent Does

### Frontend Developer
Creates production-ready React components with:
- Component architecture and reusable patterns
- React hooks and state management
- Responsive CSS and Tailwind styling
- Performance optimization
- Accessibility compliance
- Unit tests

### JavaScript Pro
Handles modern JavaScript and optimization:
- ES6+ features and async patterns
- Performance profiling and optimization
- Node.js API integration
- Event loop and race condition handling
- Bundle size optimization
- Error handling patterns

### Premium UI Designer
Elevates designs with sophisticated aesthetics:
- Typography hierarchy and visual balance
- Smooth animations and micro-interactions
- Premium color palettes and shadow systems
- Glassmorphism and modern design trends
- Loading and error states
- Mobile-first responsive design

### Meta SEO Agent
Implements SEO for Astro sites:
- Article JSON-LD schema markup
- Meta tags and Open Graph optimization
- Geographic and social media targeting
- Structured data implementation
- Schema validation

### Content Analyzer
Researches competitive landscape:
- Competitor blog analysis (last 30 days)
- Trending topics identification
- Content gap discovery
- Top-performing content formats
- Seasonal opportunities

### Content Researcher
Gathers strategic intelligence:
- Newsletter trend analysis
- Cross-source topic identification
- Emerging patterns
- Time-sensitive opportunities
- Competitive positioning

### Keyword Extractor
Finds SEO opportunities:
- Current keyword profile analysis
- Long-tail keyword opportunities
- Search volume and difficulty assessment
- Seasonal keyword patterns
- Competitive keyword gaps

### SEO Auditor
Comprehensive SEO analysis:
- Technical SEO evaluation
- Performance and Core Web Vitals
- On-page optimization review
- Content quality assessment
- Competitive benchmarking
- Action plan creation

## Request Tips for Best Results

### Be Specific About Your Goals
❌ "Build something"
✅ "Build a responsive card component with hover effects and animations"

### Mention Both Domains When Needed
❌ "I need help"
✅ "Create a blog post about React hooks that's optimized for SEO with keyword research"

### Include Context
❌ "Design a page"
✅ "Design a pricing page for my SaaS with premium animations and strong trust signals for SEO"

### Ask for Analysis
❌ "What should I do?"
✅ "Analyze my landing page for SEO opportunities and suggest improvements"

## Agent Selection Logic

The command uses intelligent pattern matching to determine which agents to dispatch:

**Design-focused requests** → frontend-developer + premium-ui-designer
**JavaScript/performance** → javascript-pro + frontend-developer
**SEO/content** → keyword-extractor + meta-seo-agent + seo-auditor
**Research-heavy** → content-analyzer + content-researcher + keyword-extractor
**Full page/product** → All relevant agents in optimal sequence

## Output Structure

When you use `/ultimate-developer`, you'll receive:

1. **Request Analysis** - Shows which agents were selected and why
2. **Agent Dispatch** - Each agent works on their specialty
3. **Integrated Results** - Findings combined into actionable deliverables
4. **Next Steps** - Recommendations for implementation or further optimization

## Advanced Usage

### Specify Agent Preferences
```bash
/ultimate-developer I need a React component, focus on performance
# Gives priority to javascript-pro for optimization

/ultimate-developer Blog post about Vue.js, heavy on SEO and research
# Prioritizes keyword-extractor and content-analyzer
```

### Multiple Goals
```bash
/ultimate-developer Build a dashboard component AND audit it for accessibility, AND optimize for performance
# Dispatches frontend-developer → premium-ui-designer → javascript-pro
```

### Competitive Research First
```bash
/ultimate-developer Research what competitors are writing about AI, then help me create better content
# Dispatches content-analyzer → content-researcher → keyword-extractor
```

## When to Use Ultimate Developer

✅ **Best For:**
- Multi-discipline projects (design + SEO)
- You're not sure which agent you need
- Building complete features from scratch
- Want expert guidance on what approach to take
- Complex projects spanning multiple domains

❌ **Not For:**
- Single focused tasks (use specific agents directly)
- Emergency fixes (go to javascript-pro or frontend-developer)
- Database schema changes
- Infrastructure/DevOps work

## Agent Specialization Reference

| Need | Primary Agent | Secondary Agents |
|------|---------------|------------------|
| React component | frontend-developer | premium-ui-designer |
| JavaScript optimization | javascript-pro | frontend-developer |
| Animations & polish | premium-ui-designer | frontend-developer |
| SEO audit | seo-auditor | keyword-extractor |
| Keyword research | keyword-extractor | content-analyzer |
| Blog content | content-analyzer | keyword-extractor, meta-seo-agent |
| Landing page | frontend-developer, premium-ui-designer | meta-seo-agent, keyword-extractor |
| Code audit/debugging | Explore | javascript-pro, frontend-developer |
| Full SaaS feature | All agents (orchestrated) | — |

## Tips for Maximum Effectiveness

1. **Describe Your End Goal** - Not just the task, but what success looks like
2. **Mention Constraints** - Budget, timeline, tech stack, brand guidelines
3. **Ask for Specific Output** - Code, research, recommendations, implementation
4. **Iterate** - Run again with feedback to refine results
5. **Save Results** - Keep the agent outputs for future reference

## Troubleshooting

### "Wrong agents dispatched"
Be more specific about what you need. Include both design and SEO keywords if you want both.

### "Want a different agent"
Run the command again with clearer keywords emphasizing the domain you need.

### "Too many agents at once"
Request a single domain (just design, just SEO) for faster, more focused results.

---

**Pro Tip**: The Ultimate Developer works best when you describe your complete vision. The more detail you provide, the better the agent selection and results.

Think of it as your technical co-founder who specializes in everything but is smart enough to delegate to experts.
