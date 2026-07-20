import re

path = 'Z:/Code/Symcon/SmartAbsenceAI/SmartActiveLighting/module.php'

with open(path, 'r', encoding='utf-8') as f:
    content = f.read()

# Pattern 1: with var_export
pattern1 = r"(if \(!@RequestAction\(([^,]+),\s*([^)]+)\)\) \{\s*\$this->SLog\('WARNING',\s*'Aktor-Befehl fehlgeschlagen',\s*\"ID: [^|]+ \| Wert: \" \. var_export\([^\)]+, true\)\);\s*\})"
replacement1 = r"\1 else { $this->SLog('INFO', 'Aktor geschaltet.', \"ID: \2 | Wert: \" . var_export(\3, true)); }"
content = re.sub(pattern1, replacement1, content)

# Pattern 2: with true/false string or 0
pattern2 = r"(if \(!@RequestAction\(([^,]+),\s*([^)]+)\)\) \{\s*\$this->SLog\('WARNING',\s*'Aktor-Befehl fehlgeschlagen',\s*\"ID: [^|]+ \| Wert: [^\"]+\"\);\s*\})"
replacement2 = r"\1 else { $this->SLog('INFO', 'Aktor geschaltet.', \"ID: \2 | Wert: \" . var_export(\3, true)); }"
content = re.sub(pattern2, replacement2, content)

# Add the sync rules log:
content = content.replace(
    "\$this->SendDebug('SyncRules', 'Fehler beim Schalten von TargetID: ' . \$targetId, 0);",
    "\$this->SLog('WARNING', 'Aktor-Befehl fehlgeschlagen', \"ID: \$targetId | Wert: \" . var_export(\$actionValue, true));\n                            } else {\n                                \$this->SLog('INFO', 'Aktor geschaltet (Sync).', \"ID: \$targetId | Wert: \" . var_export(\$actionValue, true));"
)

with open(path, 'w', encoding='utf-8') as f:
    f.write(content)

print("Done")
