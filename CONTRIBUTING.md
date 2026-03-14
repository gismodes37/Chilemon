# Contributing to ChileMon

Thank you for your interest in contributing to ChileMon.

ChileMon is an open-source project created for the AllStarLink community. Contributions are welcome from developers, node operators, testers, and documentation writers.

---

# Ways to Contribute

You can contribute in several ways:

### Bug reports

If you find a bug:

1. Check if the issue already exists
2. Open a new **GitHub Issue**
3. Provide detailed information

Include:

- operating system
- ASL version
- node number
- steps to reproduce

---

### Feature suggestions

New ideas are welcome.

Please open an **Issue** describing:

- the problem
- proposed solution
- potential benefits

---

### Code contributions

To contribute code:

1. Fork the repository
2. Create a new branch

git checkout -b feature-name

3. Commit your changes

git commit -m "Add feature description"

4. Push your branch

git push origin feature-name


5. Create a **Pull Request**

---

# Development Guidelines

Please follow these principles:

- Keep the code simple
- Avoid unnecessary dependencies
- Maintain compatibility with ASL3 nodes
- Prefer SQLite over external databases
- Do not modify Asterisk configuration files
- Do not introduce unsafe system commands

ChileMon should remain:

- lightweight
- easy to install
- safe to run on real nodes

---

# Coding Style

General guidelines:

- PHP 8+
- clear function names
- modular structure
- comments for complex logic
- avoid overly complex abstractions

Directory structure should remain consistent with the project architecture.

---

# Testing

Whenever possible test changes on:

- Raspberry Pi with ASL3
- Debian based systems

ChileMon should remain stable on real node environments.

---

# Documentation

Documentation improvements are always welcome.

Examples:

- README improvements
- installation clarification
- screenshots
- tutorials

---

Thank you for contributing to ChileMon.

