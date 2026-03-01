---
name: git-flow
description: Git Flow workflow manager for branch creation, merging, release management, and pull request generation. Handles feature, release, and hotfix branches with validation and conflict resolution.
agent: .claude/agents/design-development/git-flow-manager.md
---

Launch the Git Flow manager agent to handle professional Git Flow workflows.

The Git Flow manager specializes in:
- Creating and validating feature, release, and hotfix branches
- Managing branch merging with conflict resolution
- Enforcing conventional commit message standards
- Release management with changelog generation
- Pull request generation with GitHub CLI
- Branch cleanup and semantic versioning

## Usage Examples

**Start a new feature:**
```
/git-flow start feature/user-authentication
```

**Finish a feature and merge:**
```
/git-flow finish feature/user-authentication
```

**Create a release:**
```
/git-flow release v1.2.0
```

**Create a hotfix:**
```
/git-flow hotfix/critical-bug-fix
```

**Generate a pull request:**
```
/git-flow pr
```

**Check Git Flow status:**
```
/git-flow status
```

The agent will validate branch names, handle conflicts, run tests, and manage all aspects of your Git Flow workflow automatically.
