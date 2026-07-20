import sys
path = 'Z:/Code/Symcon/SmartAbsenceAI/SmartActiveLighting/module.php'
with open(path, 'r', encoding='utf-8') as f:
    content = f.read()

content = content.replace('\\"ID:', '"ID:')
content = content.replace('| Wert: \\"', '| Wert: "')

with open(path, 'w', encoding='utf-8') as f:
    f.write(content)
print("Done")
